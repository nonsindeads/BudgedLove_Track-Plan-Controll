(() => {
  const selectors = document.querySelectorAll('[data-chip-selector]');
  if (!selectors.length) return;

  let activeSelector = null;

  const closeAll = () => {
    selectors.forEach((selector) => {
      selector.classList.remove('show');
      const menu = selector.querySelector('.hb-tag-dropdown');
      if (menu) menu.classList.remove('show');
    });
  };

  const getTextColor = (color) => {
    const hex = (color || '').replace('#', '');
    if (hex.length !== 6 && hex.length !== 3) {
      return '#212529';
    }
    const full = hex.length === 3 ? hex.split('').map((c) => c + c).join('') : hex;
    const r = parseInt(full.slice(0, 2), 16);
    const g = parseInt(full.slice(2, 4), 16);
    const b = parseInt(full.slice(4, 6), 16);
    const luma = 0.2126 * r + 0.7152 * g + 0.0722 * b;
    return luma > 160 ? '#212529' : '#ffffff';
  };

  const removeSelected = (selector, tagId) => {
    selector.querySelector(`.hb-tag-chip[data-tag-id="${tagId}"]`)?.remove();
    selector.querySelector(`.hb-tag-values input[value="${tagId}"]`)?.remove();
    const option = selector.querySelector(`.hb-tag-option[data-tag-id="${tagId}"]`);
    if (option) option.classList.remove('active');
  };

  const setSelected = (selector, tagId, tagName, tagColor) => {
    const multi = selector.dataset.selectorMulti !== 'false';
    const values = selector.querySelector('.hb-tag-values');
    if (!values) return;
    if (!multi) {
      values.querySelectorAll('input').forEach((input) => removeSelected(selector, input.value));
    }
    if (values.querySelector(`input[value="${tagId}"]`)) {
      return;
    }

    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = selector.dataset.selectorName || 'tag_ids[]';
    input.value = tagId;
    values.appendChild(input);

    const chip = document.createElement('span');
    chip.className = 'badge hb-tag-chip';
    chip.dataset.tagId = tagId;
    const bg = tagColor || '#e9ecef';
    chip.style.backgroundColor = bg;
    chip.style.color = tagColor ? getTextColor(tagColor) : '#212529';
    chip.textContent = tagName;
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'btn-close ms-1 hb-tag-remove';
    if (tagColor) remove.classList.add('btn-close-white');
    remove.setAttribute('aria-label', 'Entfernen');
    chip.appendChild(remove);

    selector.querySelector('.hb-tag-field')?.insertBefore(chip, selector.querySelector('.hb-tag-input'));
    const option = selector.querySelector(`.hb-tag-option[data-tag-id="${tagId}"]`);
    if (option) option.classList.add('active');
  };

  selectors.forEach((selector) => {
    const input = selector.querySelector('.hb-tag-input');
    const field = selector.querySelector('.hb-tag-field');
    const menu = selector.querySelector('.hb-tag-dropdown');
    const toggle = selector.querySelector('[data-bs-toggle="dropdown"]');
    const dropdown = toggle ? bootstrap.Dropdown.getOrCreateInstance(toggle) : null;

    const openDropdown = () => {
      if (dropdown && !dropdown._menu.classList.contains('show')) {
        dropdown.show();
      }
      selector.classList.add('show');
      menu?.classList.add('show');
      input?.focus();
    };

    const filterOptions = (term) => {
      const needle = term.trim().toLowerCase();
      selector.querySelectorAll('.hb-tag-option').forEach((option) => {
        const name = option.dataset.tagName?.toLowerCase() || '';
        option.classList.toggle('d-none', needle !== '' && !name.includes(needle));
      });
    };

    field?.addEventListener('click', () => {
      if (input?.disabled || input?.readOnly) return;
      openDropdown();
      input?.focus();
    });

    input?.addEventListener('focus', () => {
      activeSelector = selector;
      openDropdown();
    });

    input?.addEventListener('input', (event) => {
      event.stopPropagation();
      filterOptions(event.target.value);
      openDropdown();
    });

    input?.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        closeAll();
        input.blur();
      }
      if (event.key === 'Backspace' && !input.value) {
        const chips = selector.querySelectorAll('.hb-tag-chip');
        const last = chips[chips.length - 1];
        if (last) {
          removeSelected(selector, last.dataset.tagId);
        }
      }
    });

    selector.querySelectorAll('.hb-tag-option').forEach((option) => {
      option.addEventListener('click', (event) => {
        event.preventDefault();
        const { tagId, tagName, tagColor } = option.dataset;
        setSelected(selector, tagId, tagName, tagColor);
        input.value = '';
        filterOptions('');
        openDropdown();
        input.focus();
      });
    });

    selector.addEventListener('click', (event) => {
      if (event.target.classList.contains('hb-tag-remove')) {
        const chip = event.target.closest('.hb-tag-chip');
        if (chip) {
          removeSelected(selector, chip.dataset.tagId);
          input.focus();
        }
      }
    });
  });

  document.addEventListener('click', (event) => {
    if (!event.target.closest('[data-chip-selector]') && !event.target.closest('.modal')) {
      closeAll();
    }
  });

  const tagModal = document.querySelector('.hb-tag-modal-form');
  if (tagModal) {
    const colorInput = tagModal.querySelector('input[name="color"]');
    const picker = tagModal.querySelector('input[name="color_picker"]');
    const syncPicker = (value) => {
      if (!picker) return;
      const hex = (value || '').trim();
      if (/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(hex)) {
        picker.value = hex;
      }
    };
    if (picker && colorInput) {
      picker.addEventListener('input', () => {
        colorInput.value = picker.value;
      });
      colorInput.addEventListener('input', () => {
        syncPicker(colorInput.value);
      });
    }

    tagModal.addEventListener('submit', async (event) => {
      event.preventDefault();
      const form = event.target;
      const nameInput = form.querySelector('input[name="name"]');
      if (!nameInput || !nameInput.value.trim()) {
        nameInput?.classList.add('is-invalid');
        return;
      }
      nameInput.classList.remove('is-invalid');

      if (picker && colorInput && !colorInput.value) {
        colorInput.value = picker.value;
      }

      const formData = new FormData(form);
      const response = await fetch('/tags.php?action=create', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });

      if (!response.ok) {
        nameInput.classList.add('is-invalid');
        return;
      }

      const payload = await response.json();
      if (!payload || !payload.id) return;
      selectors.forEach((selector) => {
        if (!selector.classList.contains('hb-tag-selector')) return;
        const menu = selector.querySelector('.hb-tag-options');
        if (!menu) return;
        const exists = selector.querySelector(`.hb-tag-option[data-tag-id="${payload.id}"]`);
        if (exists) return;
        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'dropdown-item d-flex align-items-center hb-tag-option';
        item.dataset.tagId = payload.id;
        item.dataset.tagName = payload.name || '';
        item.dataset.tagColor = payload.color || '';
        item.innerHTML = `<span class="hb-tag-dot" style="${payload.color ? `background-color:${payload.color};` : ''}"></span><span>${payload.name}</span>`;
        item.addEventListener('click', (event) => {
          event.preventDefault();
          setSelected(selector, payload.id, payload.name || '', payload.color || '');
        });
        menu.appendChild(item);
      });

      if (activeSelector) {
        setSelected(activeSelector, payload.id, payload.name || '', payload.color || '');
      }

      const modalInstance = bootstrap.Modal.getInstance(form.closest('.modal'));
      modalInstance?.hide();
      form.reset();
    });
  }

  const categoryModal = document.querySelector('.hb-category-modal-form');
  if (categoryModal) {
    categoryModal.addEventListener('submit', async (event) => {
      event.preventDefault();
      const form = event.target;
      const nameInput = form.querySelector('input[name="name"]');
      if (!nameInput || !nameInput.value.trim()) {
        nameInput?.classList.add('is-invalid');
        return;
      }
      nameInput.classList.remove('is-invalid');
      const formData = new FormData(form);
      const response = await fetch('/categories.php?action=create', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
      if (!response.ok) {
        nameInput.classList.add('is-invalid');
        return;
      }
      const payload = await response.json();
      if (!payload || !payload.id) return;
      selectors.forEach((selector) => {
        if (!selector.classList.contains('hb-category-selector')) return;
        const menu = selector.querySelector('.hb-tag-options');
        if (!menu) return;
        const exists = selector.querySelector(`.hb-tag-option[data-tag-id="${payload.id}"]`);
        if (exists) return;
        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'dropdown-item d-flex align-items-center hb-tag-option';
        item.dataset.tagId = payload.id;
        item.dataset.tagName = payload.name || '';
        item.dataset.tagColor = '';
        item.innerHTML = `<span class="hb-tag-dot"></span><span>${payload.name}</span><span class="text-muted small ms-auto">${payload.type}</span>`;
        item.addEventListener('click', (event) => {
          event.preventDefault();
          setSelected(selector, payload.id, payload.name || '', '');
        });
        menu.appendChild(item);
      });
      if (activeSelector) {
        setSelected(activeSelector, payload.id, payload.name || '', '');
      }
      const modalInstance = bootstrap.Modal.getInstance(form.closest('.modal'));
      modalInstance?.hide();
      form.reset();
    });
  }

  const payeeModal = document.querySelector('.hb-payee-modal-form');
  if (payeeModal) {
    payeeModal.addEventListener('submit', async (event) => {
      event.preventDefault();
      const form = event.target;
      const nameInput = form.querySelector('input[name="name"]');
      if (!nameInput || !nameInput.value.trim()) {
        nameInput?.classList.add('is-invalid');
        return;
      }
      nameInput.classList.remove('is-invalid');
      const formData = new FormData(form);
      const response = await fetch('/payees.php?action=create', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
      if (!response.ok) {
        nameInput.classList.add('is-invalid');
        return;
      }
      const payload = await response.json();
      if (!payload || !payload.id) return;
      selectors.forEach((selector) => {
        if (!selector.classList.contains('hb-payee-selector')) return;
        const menu = selector.querySelector('.hb-tag-options');
        if (!menu) return;
        const exists = selector.querySelector(`.hb-tag-option[data-tag-id="${payload.id}"]`);
        if (exists) return;
        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'dropdown-item d-flex align-items-center hb-tag-option';
        item.dataset.tagId = payload.id;
        item.dataset.tagName = payload.name || '';
        item.dataset.tagColor = '';
        item.innerHTML = `<span class="hb-tag-dot"></span><span>${payload.name}</span>`;
        item.addEventListener('click', (event) => {
          event.preventDefault();
          setSelected(selector, payload.id, payload.name || '', '');
        });
        menu.appendChild(item);
      });
      if (activeSelector) {
        setSelected(activeSelector, payload.id, payload.name || '', '');
      }
      const modalInstance = bootstrap.Modal.getInstance(form.closest('.modal'));
      modalInstance?.hide();
      form.reset();
    });
  }
})();
