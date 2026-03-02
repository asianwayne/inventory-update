document.addEventListener("DOMContentLoaded", function () {
  function initializeSelectSearch() {
    const sourceSelects = document.querySelectorAll("select:not([multiple])");
    let openDropdown = null;

    function closeOpenDropdown() {
      if (!openDropdown) {
        return;
      }
      openDropdown.classList.remove("open");
      const trigger = openDropdown.querySelector(".local-select-trigger");
      if (trigger) {
        trigger.setAttribute("aria-expanded", "false");
      }
      if (typeof openDropdown._resetSearch === "function") {
        openDropdown._resetSearch();
      }
      openDropdown = null;
    }

    sourceSelects.forEach(function (select) {
      if (select.dataset.searchableSelect === "ready") {
        return;
      }

      const options = Array.from(select.options).map(function (option, index) {
        return {
          value: option.value,
          text: option.textContent || "",
          disabled: option.disabled,
          index: index
        };
      });

      const wrapper = document.createElement("div");
      wrapper.className = "local-select";

      const trigger = document.createElement("button");
      trigger.type = "button";
      trigger.className = "local-select-trigger";
      trigger.setAttribute("aria-haspopup", "listbox");
      trigger.setAttribute("aria-expanded", "false");

      const triggerLabel = document.createElement("span");
      triggerLabel.className = "local-select-label";
      trigger.appendChild(triggerLabel);

      const triggerIcon = document.createElement("i");
      triggerIcon.className = "ph-bold ph-caret-down";
      trigger.appendChild(triggerIcon);

      const dropdown = document.createElement("div");
      dropdown.className = "local-select-dropdown";

      const searchInput = document.createElement("input");
      searchInput.type = "text";
      searchInput.className = "local-select-search";
      searchInput.placeholder = "Type to search...";
      searchInput.autocomplete = "off";
      dropdown.appendChild(searchInput);

      const optionsList = document.createElement("ul");
      optionsList.className = "local-select-options";
      optionsList.setAttribute("role", "listbox");
      dropdown.appendChild(optionsList);

      wrapper.appendChild(trigger);
      wrapper.appendChild(dropdown);

      select.classList.add("local-select-source");
      select.parentNode.insertBefore(wrapper, select.nextSibling);

      function selectedText() {
        const selectedOption = select.options[select.selectedIndex];
        if (!selectedOption) {
          return "Select";
        }
        return selectedOption.textContent || "Select";
      }

      function updateTriggerLabel() {
        triggerLabel.textContent = selectedText();
      }

      function renderOptions(query) {
        const search = String(query || "").toLowerCase().trim();
        const fragment = document.createDocumentFragment();

        let matchCount = 0;
        options.forEach(function (item) {
          if (search && !item.text.toLowerCase().includes(search)) {
            return;
          }

          const itemButton = document.createElement("button");
          itemButton.type = "button";
          itemButton.className = "local-select-option";
          itemButton.textContent = item.text;
          itemButton.disabled = item.disabled;
          itemButton.setAttribute("role", "option");

          if (select.value === item.value) {
            itemButton.classList.add("is-selected");
            itemButton.setAttribute("aria-selected", "true");
          } else {
            itemButton.setAttribute("aria-selected", "false");
          }

          itemButton.addEventListener("click", function () {
            if (item.disabled) {
              return;
            }
            select.selectedIndex = item.index;
            select.dispatchEvent(new Event("change", { bubbles: true }));
            updateTriggerLabel();
            closeOpenDropdown();
          });

          const li = document.createElement("li");
          li.appendChild(itemButton);
          fragment.appendChild(li);
          matchCount++;
        });

        optionsList.innerHTML = "";
        if (matchCount === 0) {
          const emptyLi = document.createElement("li");
          const empty = document.createElement("div");
          empty.className = "local-select-empty";
          empty.textContent = "No matching items";
          emptyLi.appendChild(empty);
          optionsList.appendChild(emptyLi);
          return;
        }

        optionsList.appendChild(fragment);
      }

      updateTriggerLabel();
      renderOptions("");
      wrapper._resetSearch = function () {
        searchInput.value = "";
        renderOptions("");
      };

      trigger.addEventListener("click", function () {
        const isOpen = wrapper.classList.contains("open");
        closeOpenDropdown();
        if (isOpen) {
          return;
        }
        wrapper.classList.add("open");
        trigger.setAttribute("aria-expanded", "true");
        wrapper._resetSearch();
        searchInput.focus();
        openDropdown = wrapper;
      });

      searchInput.addEventListener("input", function () {
        renderOptions(searchInput.value);
      });

      select.addEventListener("change", function () {
        updateTriggerLabel();
        renderOptions(searchInput.value);
      });

      select.dataset.searchableSelect = "ready";
    });

    document.addEventListener("click", function (event) {
      if (!openDropdown) {
        return;
      }
      if (!openDropdown.contains(event.target)) {
        openDropdown.classList.remove("open");
        openDropdown.querySelector(".local-select-trigger").setAttribute("aria-expanded", "false");
        openDropdown = null;
      }
    });

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && openDropdown) {
        openDropdown.classList.remove("open");
        openDropdown.querySelector(".local-select-trigger").setAttribute("aria-expanded", "false");
        openDropdown = null;
      }
    });
  }

  // Sidebar Toggles
  const sidebar = document.getElementById("sidebar");
  const menuToggle = document.getElementById("menuToggle");
  const mobileToggle = document.getElementById("mobileToggle");

  if (menuToggle && sidebar) {
    menuToggle.addEventListener("click", () => {
      sidebar.classList.toggle("open");
    });
  }

  if (mobileToggle && sidebar) {
    mobileToggle.addEventListener("click", () => {
      sidebar.classList.remove("open");
    });
  }

  // Close sidebar on click outside on mobile
  document.addEventListener("click", (e) => {
    if (window.innerWidth <= 1024 && sidebar && sidebar.classList.contains("open")) {
      if (!sidebar.contains(e.target) && !menuToggle.contains(e.target)) {
        sidebar.classList.remove("open");
      }
    }
  });

  const autoHide = document.querySelector(".alert");
  if (autoHide) {
    setTimeout(function () {
      autoHide.style.opacity = "0";
      autoHide.style.transition = "opacity .3s ease";
      setTimeout(() => autoHide.remove(), 300);
    }, 3500);
  }

  const links = document.querySelectorAll(".image-preview-link");
  if (links.length > 0) {
    const modal = document.createElement("div");
    modal.className = "image-lightbox";
    modal.innerHTML =
      '<div class="image-lightbox-inner"><button type="button" class="image-lightbox-close" aria-label="Close"><i class="ph-bold ph-x"></i></button><img src="" alt="Full view"></div>';
    document.body.appendChild(modal);

    const modalImage = modal.querySelector("img");
    const closeBtn = modal.querySelector(".image-lightbox-close");

    function closeLightbox() {
      modal.classList.remove("open");
      modalImage.setAttribute("src", "");
    }

    links.forEach(function (link) {
      link.addEventListener("click", function (event) {
        event.preventDefault();
        const fullSrc = link.getAttribute("href");
        if (!fullSrc) {
          return;
        }
        modalImage.setAttribute("src", fullSrc);
        modal.classList.add("open");
      });
    });

    closeBtn.addEventListener("click", closeLightbox);
    modal.addEventListener("click", function (event) {
      if (event.target === modal) {
        closeLightbox();
      }
    });
    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape") {
        closeLightbox();
      }
    });
  }

  initializeSelectSearch();
});
