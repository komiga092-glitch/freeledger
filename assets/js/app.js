(function () {
  const BREAKPOINT = 1200;

  function getElements() {
    return {
      sidebar: document.getElementById("sidebar"),
      overlay: document.getElementById("sidebarOverlay"),
      toggleBtn: document.getElementById("sidebarToggle"),
    };
  }

  function isMobileLayout() {
    return window.innerWidth <= BREAKPOINT;
  }

  window.openSidebar = function () {
    const { sidebar, overlay } = getElements();
    if (!sidebar || !overlay) return;

    sidebar.classList.add("show");
    overlay.classList.add("show");
    document.body.classList.add("sidebar-open");
  };

  window.closeSidebar = function () {
    const { sidebar, overlay } = getElements();
    if (!sidebar || !overlay) return;

    sidebar.classList.remove("show");
    overlay.classList.remove("show");
    document.body.classList.remove("sidebar-open");
  };

  window.toggleSidebar = function () {
    const { sidebar } = getElements();
    if (!sidebar) return;

    if (sidebar.classList.contains("show")) {
      window.closeSidebar();
    } else {
      window.openSidebar();
    }
  };

  document.addEventListener("DOMContentLoaded", function () {
    const { sidebar, overlay, toggleBtn } = getElements();

    if (toggleBtn) {
      toggleBtn.addEventListener("click", function (e) {
        e.preventDefault();
        e.stopPropagation();
        window.toggleSidebar();
      });
    }

    if (overlay) {
      overlay.addEventListener("click", function () {
        window.closeSidebar();
      });
    }

    document.querySelectorAll("#sidebar a").forEach(function (link) {
      link.addEventListener("click", function () {
        if (isMobileLayout()) {
          window.closeSidebar();
        }
      });
    });

    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape") {
        window.closeSidebar();
      }
    });

    window.addEventListener("resize", function () {
      if (!isMobileLayout()) {
        window.closeSidebar();
      }
    });

    document.querySelectorAll("[data-toggle-password]").forEach(function (btn) {
      btn.addEventListener("click", function (e) {
        e.preventDefault();

        const inputId = this.getAttribute("data-toggle-password");
        const input = document.getElementById(inputId);
        const icon = this.querySelector(".eye-icon");

        if (!input) return;

        input.type = input.type === "password" ? "text" : "password";

        if (icon) {
          icon.textContent = input.type === "password" ? "👁" : "🙈";
        }

        this.setAttribute(
          "title",
          input.type === "password" ? "Show password" : "Hide password",
        );
      });
    });

    // Notification functions
    window.toggleNotifications = function () {
      const dropdown = document.getElementById("notificationDropdown");
      if (dropdown) {
        dropdown.style.display =
          dropdown.style.display === "block" ? "none" : "block";
      }
    };

    window.markAsRead = function (notificationId, url) {
      // Mark as read via AJAX
      fetch("mark_notification_read.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body:
          "notification_id=" +
          notificationId +
          "&csrf_token=" +
          encodeURIComponent(
            document.querySelector('input[name="csrf_token"]')?.value || "",
          ),
      })
        .then((response) => response.json())
        .then((data) => {
          if (data.success) {
            // Update UI
            const item = document.querySelector(
              '.notification-item[onclick*="markAsRead(' +
                notificationId +
                '"]',
            );
            if (item) {
              item.classList.remove("unread");
            }
            // Update badge count
            const badge = document.querySelector(".notification-count");
            if (badge) {
              const current = parseInt(badge.textContent) || 0;
              if (current > 1) {
                badge.textContent = current - 1;
              } else {
                badge.remove();
              }
            }
          }
        })
        .catch((error) =>
          console.error("Error marking notification as read:", error),
        );

      // Navigate to URL
      if (url) {
        window.location.href = url;
      }
    };

    // Close dropdown when clicking outside
    document.addEventListener("click", function (e) {
      const container = document.querySelector(".notification-container");
      const dropdown = document.getElementById("notificationDropdown");
      if (container && dropdown && !container.contains(e.target)) {
        dropdown.style.display = "none";
      }
    });
  });
})();
