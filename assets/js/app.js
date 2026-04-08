document.addEventListener("DOMContentLoaded", function () {
  const sidebar = document.getElementById("sidebar");
  const overlay = document.getElementById("sidebarOverlay");
  const toggleBtn = document.getElementById("menuToggle");

  function openSidebar() {
    if (sidebar) {
      sidebar.classList.add("show");
      sidebar.style.transform = "translateX(0)";
    }
    if (overlay) {
      overlay.classList.add("show");
      overlay.style.opacity = "1";
      overlay.style.visibility = "visible";
    }
    document.body.style.overflow = "hidden";
  }

  function closeSidebar() {
    if (sidebar) {
      sidebar.classList.remove("show");
      sidebar.style.transform = "translateX(-100%)";
    }
    if (overlay) {
      overlay.classList.remove("show");
      overlay.style.opacity = "0";
      overlay.style.visibility = "hidden";
    }
    document.body.style.overflow = "";
  }

  // Toggle button functionality
  if (toggleBtn) {
    toggleBtn.addEventListener("click", function (e) {
      e.preventDefault();
      e.stopPropagation();
      if (sidebar && sidebar.classList.contains("show")) {
        closeSidebar();
      } else {
        openSidebar();
      }
    });
  }

  // Overlay click to close sidebar
  if (overlay) {
    overlay.addEventListener("click", function (e) {
      e.preventDefault();
      closeSidebar();
    });
  }

  // Close sidebar when clicking on a menu link (mobile)
  document.querySelectorAll("#sidebar a").forEach(function (link) {
    link.addEventListener("click", function () {
      if (window.innerWidth <= 900) {
        closeSidebar();
      }
    });
  });

  // Close sidebar on window resize if width exceeds threshold
  window.addEventListener("resize", function () {
    if (window.innerWidth > 900) {
      closeSidebar();
    }
  });

  // Prevent sidebar closing on link clicks inside sidebar (for dropdowns, etc)
  const sidebarMenu = document.querySelector(".sidebar-menu");
  if (sidebarMenu) {
    sidebarMenu.addEventListener("click", function (e) {
      if (e.target !== e.currentTarget) {
        e.stopPropagation();
      }
    });
  }

  // Password toggle functionality
  const passwordToggles = document.querySelectorAll("[data-toggle-password]");

  passwordToggles.forEach(function (btn) {
    btn.addEventListener("click", function (e) {
      e.preventDefault();
      e.stopPropagation();

      const inputId = this.getAttribute("data-toggle-password");
      const input = document.getElementById(inputId);

      if (!input) {
        console.warn("Password input element not found:", inputId);
        return;
      }

      const icon = this.querySelector(".eye-icon");

      if (input.type === "password") {
        input.type = "text";
        if (icon) icon.textContent = "🙈";
        this.setAttribute("aria-pressed", "true");
        this.setAttribute("title", "Hide password");
      } else {
        input.type = "password";
        if (icon) icon.textContent = "👁";
        this.setAttribute("aria-pressed", "false");
        this.setAttribute("title", "Show password");
      }
    });

    // Keyboard support for password toggle (Spacebar or Enter)
    btn.addEventListener("keydown", function (e) {
      if (e.key === " " || e.key === "Enter") {
        e.preventDefault();
        this.click();
      }
    });
  });

  // Prevent form submission on password toggle button click
  document.querySelectorAll(".password-toggle").forEach(function (btn) {
    btn.addEventListener("click", function (e) {
      if (e.button === 0) {
        e.preventDefault();
        return false;
      }
    });
  });
});
