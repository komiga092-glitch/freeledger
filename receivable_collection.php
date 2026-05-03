<?php
declare(strict_types=1);

session_start();

require_once 'config/db.php';
require_once 'includes/security.php';
require_once 'includes/functions.php';

set_security_headers();

if (!isset($_SESSION['user_id']) || (int)($_SESSION['user_id'] ?? 0) <= 0) {
    header("Location: login.php");
    exit;
}

$company_id = (int)($_SESSION['company_id'] ?? 0);
$user_id    = (int)($_SESSION['user_id'] ?? 0);

if ($company_id <= 0) {
    header("Location: dashboard.php");
    exit;
}

$currentRole = verify_user_role($user_id, $company_id);
$canCrud = in_array($currentRole, ['organization', 'accountant'], true);

if (!$canCrud) {
    header("Location: receivables.php");
    exit;
}

$pageTitle = 'Collect Receivable';
$pageDescription = 'Record money received from loans or receivables';

$msg = '';
$msgType = 'success';

function failIfPrepareFalse($stmt, string $context = 'Database prepare failed'): mysqli_stmt
{
    if ($stmt === false) {
        throw new RuntimeException($context);
    }
    return $stmt;
}

function isValidIsoDate(string $date): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return $dt !== false && $dt->format('Y-m-d') === $date;
}

function getReceivableById(mysqli $conn, int $company_id, int $receivable_id): ?array
{
    $stmt = failIfPrepareFalse($conn->prepare("
        SELECT *
        FROM receivables
        WHERE company_id = ? AND receivable_id = ?
        LIMIT 1
    "));

    $stmt->bind_param('ii', $company_id, $receivable_id);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function insertCollectionLedger(
    mysqli $conn,
    int $company_id,
    int $collection_id,
    string $borrower_name,
    string $collection_date,
    float $amount,
    string $source,
    string $bank_name,
    string $account_number
): array {
    $description = '[RECEIVABLE_COLLECTION:' . $collection_id . '] Collection from ' . $borrower_name;

    if ($source === 'Cash') {
        $stmt = failIfPrepareFalse($conn->prepare("
            INSERT INTO cash_account (
                company_id,
                transaction_date,
                description,
                transaction_type,
                amount
            )
            VALUES (?, ?, ?, 'Cash In', ?)
        "));

        $stmt->bind_param('issd', $company_id, $collection_date, $description, $amount);
        $stmt->execute();

        $cash_id = (int)$stmt->insert_id;
        $stmt->close();

        return ['cash_id' => $cash_id, 'bank_id' => null];
    }

    $stmt = failIfPrepareFalse($conn->prepare("
        INSERT INTO bank_account (
            company_id,
            transaction_date,
            description,
            amount,
            transaction_type,
            bank_name,
            account_number
        )
        VALUES (?, ?, ?, ?, 'Deposit', ?, ?)
    "));

    $stmt->bind_param(
        'issdss',
        $company_id,
        $collection_date,
        $description,
        $amount,
        $bank_name,
        $account_number
    );

    $stmt->execute();

    $bank_id = (int)$stmt->insert_id;
    $stmt->close();

    return ['cash_id' => null, 'bank_id' => $bank_id];
}

$selected_receivable_id = (int)($_GET['id'] ?? $_POST['receivable_id'] ?? 0);
$selected_receivable = null;

if ($selected_receivable_id > 0) {
    $selected_receivable = getReceivableById($conn, $company_id, $selected_receivable_id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_collection'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid request.';
        $msgType = 'danger';
    } else {
        $receivable_id = (int)($_POST['receivable_id'] ?? 0);
        $collection_date = trim($_POST['collection_date'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $source = trim($_POST['collection_source'] ?? 'Cash');
        $bank_name = trim($_POST['bank_name'] ?? '');
        $account_number = trim($_POST['account_number'] ?? '');
        $reference_no = trim($_POST['reference_no'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if (!in_array($source, ['Cash', 'Bank'], true)) {
            $source = 'Cash';
        }

        if ($source !== 'Bank') {
            $bank_name = '';
            $account_number = '';
        }

        try {
            if ($receivable_id <= 0) {
                throw new RuntimeException('Please select a receivable.');
            }

            if ($collection_date === '' || !isValidIsoDate($collection_date)) {
                throw new RuntimeException('Please enter a valid collection date.');
            }

            if ($amount <= 0) {
                throw new RuntimeException('Please enter a valid amount.');
            }

            if ($source === 'Bank' && ($bank_name === '' || $account_number === '')) {
                throw new RuntimeException('Bank name and account number are required.');
            }

            $receivable = getReceivableById($conn, $company_id, $receivable_id);

            if (!$receivable) {
                throw new RuntimeException('Receivable not found.');
            }

            $borrower_name = (string)$receivable['borrower_name'];
            $original_amount = (float)$receivable['original_amount'];
            $received_amount = (float)$receivable['received_amount'];
            $balance_amount = (float)$receivable['balance_amount'];

            if ($balance_amount <= 0) {
                throw new RuntimeException('This receivable is already fully received.');
            }

            if ($amount > $balance_amount) {
                throw new RuntimeException('Collection amount cannot exceed outstanding balance.');
            }

            $conn->begin_transaction();

            $stmt = failIfPrepareFalse($conn->prepare("
                INSERT INTO receivable_collections (
                    receivable_id,
                    company_id,
                    collection_date,
                    amount,
                    collection_source,
                    bank_name,
                    account_number,
                    reference_no,
                    notes,
                    created_by
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            "));

            $stmt->bind_param(
                'iisdsssssi',
                $receivable_id,
                $company_id,
                $collection_date,
                $amount,
                $source,
                $bank_name,
                $account_number,
                $reference_no,
                $notes,
                $user_id
            );

            $stmt->execute();
            $collection_id = (int)$stmt->insert_id;
            $stmt->close();

            insertCollectionLedger(
                $conn,
                $company_id,
                $collection_id,
                $borrower_name,
                $collection_date,
                $amount,
                $source,
                $bank_name,
                $account_number
            );

            $new_received_amount = $received_amount + $amount;
            $new_balance_amount = $original_amount - $new_received_amount;

            if ($new_balance_amount < 0) {
                $new_balance_amount = 0;
            }

            $new_status = $new_balance_amount <= 0 ? 'Received' : 'Partially Received';

            $stmt = failIfPrepareFalse($conn->prepare("
                UPDATE receivables
                SET received_amount = ?,
                    balance_amount = ?,
                    status = ?
                WHERE company_id = ? AND receivable_id = ?
            "));

            $stmt->bind_param(
                'ddsii',
                $new_received_amount,
                $new_balance_amount,
                $new_status,
                $company_id,
                $receivable_id
            );

            $stmt->execute();
            $stmt->close();

            $conn->commit();

            header("Location: receivables.php?collection=success");
            exit;

        } catch (Throwable $e) {
            try {
                $conn->rollback();
            } catch (Throwable $ignore) {
            }

            $msg = $e->getMessage();
            $msgType = 'danger';
        }
    }
}

$receivables = [];

$stmt = failIfPrepareFalse($conn->prepare("
    SELECT receivable_id, borrower_name, receivable_type, original_amount, received_amount, balance_amount
    FROM receivables
    WHERE company_id = ?
    AND balance_amount > 0
    ORDER BY borrower_name ASC
"));

$stmt->bind_param('i', $company_id);
$stmt->execute();

$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $receivables[] = $row;
}

$stmt->close();

$collections = [];

if ($selected_receivable_id > 0) {
    $stmt = failIfPrepareFalse($conn->prepare("
        SELECT *
        FROM receivable_collections
        WHERE company_id = ? AND receivable_id = ?
        ORDER BY collection_date DESC, collection_id DESC
    "));

    $stmt->bind_param('ii', $company_id, $selected_receivable_id);
    $stmt->execute();

    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $collections[] = $row;
    }

    $stmt->close();
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-area">
    <div class="topbar">
        <div class="topbar-left">
            <button class="menu-toggle" id="menuToggle">☰</button>
            <div class="page-heading">
                <h1>Collect Receivable</h1>
                <p><?= e($pageDescription) ?></p>
            </div>
        </div>

        <div class="topbar-right">
            <div class="company-pill"><?= e($_SESSION['company_name'] ?? 'Company') ?></div>
            <div class="role-pill"><?= e($_SESSION['role'] ?? 'User') ?></div>
        </div>
    </div>

    <div class="content">
        <?php if ($msg !== ''): ?>
        <div class="alert alert-<?= e($msgType) ?>"><?= e($msg) ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h3>Record Collection</h3>
                <span class="badge badge-primary">Money Received</span>
            </div>

            <div class="card-body">
                <form method="POST">
                    <?= csrf_field() ?>

                    <div class="form-grid">
                        <div class="form-group full">
                            <label class="form-label">Select Receivable</label>
                            <select name="receivable_id" id="receivableSelect" class="form-control" required>
                                <option value="">-- Select Receivable --</option>
                                <?php foreach ($receivables as $receivable): ?>
                                <option value="<?= (int)$receivable['receivable_id'] ?>"
                                    data-balance="<?= e((string)$receivable['balance_amount']) ?>"
                                    <?= $selected_receivable_id === (int)$receivable['receivable_id'] ? 'selected' : '' ?>>
                                    <?= e((string)$receivable['borrower_name']) ?>
                                    - <?= e((string)$receivable['receivable_type']) ?>
                                    - Balance Rs. <?= number_format((float)$receivable['balance_amount'], 2) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Outstanding Balance</label>
                            <input type="text" id="outstandingBalance" class="form-control" readonly
                                value="<?= $selected_receivable ? 'Rs. ' . number_format((float)$selected_receivable['balance_amount'], 2) : '' ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Collection Date</label>
                            <input type="date" name="collection_date" class="form-control" value="<?= date('Y-m-d') ?>"
                                required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Collection Amount</label>
                            <input type="number" step="0.01" min="0.01" name="amount" id="collectionAmount"
                                class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Received To</label>
                            <select name="collection_source" id="collectionSource" class="form-control" required>
                                <option value="Cash">Cash</option>
                                <option value="Bank">Bank</option>
                            </select>
                        </div>

                        <div class="form-group hidden" id="bankNameWrap">
                            <label class="form-label">Bank Name</label>
                            <input type="text" name="bank_name" class="form-control">
                        </div>

                        <div class="form-group hidden" id="accountNoWrap">
                            <label class="form-label">Account Number</label>
                            <input type="text" name="account_number" class="form-control">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Reference No</label>
                            <input type="text" name="reference_no" class="form-control"
                                placeholder="Receipt / Voucher / Bank Ref">
                        </div>

                        <div class="form-group full">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control"
                                placeholder="Optional collection notes"></textarea>
                        </div>

                        <div class="form-group full">
                            <button type="submit" name="save_collection" class="btn btn-primary">Save
                                Collection</button>
                            <a href="receivables.php" class="btn btn-light">Back</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <?php if (!empty($collections)): ?>
        <div class="card mt-24">
            <div class="card-header">
                <h3>Collection History</h3>
                <span class="badge badge-success"><?= count($collections) ?> Records</span>
            </div>

            <div class="card-body">
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Received To</th>
                                <th>Reference</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($collections as $collection): ?>
                            <tr>
                                <td><?= e((string)$collection['collection_id']) ?></td>
                                <td><?= e((string)$collection['collection_date']) ?></td>
                                <td>Rs. <?= number_format((float)$collection['amount'], 2) ?></td>
                                <td><?= e((string)$collection['collection_source']) ?></td>
                                <td><?= e((string)($collection['reference_no'] ?? '')) ?></td>
                                <td><?= e((string)($collection['notes'] ?? '')) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<script>
const receivableSelect = document.getElementById('receivableSelect');
const outstandingBalance = document.getElementById('outstandingBalance');
const collectionAmount = document.getElementById('collectionAmount');

function updateBalanceDisplay() {
    if (!receivableSelect || !outstandingBalance) return;

    const selected = receivableSelect.options[receivableSelect.selectedIndex];
    const balance = selected ? selected.getAttribute('data-balance') : '';

    outstandingBalance.value = balance ? 'Rs. ' + Number(balance).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }) : '';

    if (collectionAmount && balance) {
        collectionAmount.max = balance;
    }
}

if (receivableSelect) {
    receivableSelect.addEventListener('change', updateBalanceDisplay);
    updateBalanceDisplay();
}

const collectionSource = document.getElementById('collectionSource');
const bankNameWrap = document.getElementById('bankNameWrap');
const accountNoWrap = document.getElementById('accountNoWrap');

function toggleBankFields() {
    if (!collectionSource || !bankNameWrap || !accountNoWrap) return;

    bankNameWrap.style.display = collectionSource.value === 'Bank' ? 'block' : 'none';
    accountNoWrap.style.display = collectionSource.value === 'Bank' ? 'block' : 'none';
}

if (collectionSource) {
    toggleBankFields();
    collectionSource.addEventListener('change', toggleBankFields);
}
</script>