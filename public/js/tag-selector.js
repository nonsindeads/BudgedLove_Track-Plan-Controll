(() => {
  const selectors = document.querySelectorAll('[data-tag-selector]');
  if (!selectors.length) return;

  let activeSelector = null;

  const closeAll = () => {
    selectors.forEach((selector) => {
      selector.classList.remove('show');
      const menu = selector.querySelector('.hb-tag-dropdown');
      if (menu) {
        menu.classList.remove('show');
      }
    });
  };

  const setSelected = (selector, tagId, tagName, tagColor) => {
    const values = selector.querySelector('.hb-tag-values');
    if (!values || values.querySelector(`input[value="${tagId}"]`)) {
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
    if (tagColor) {
      chip.style.backgroundColor = tagColor;
    }
    chip.textContent = tagName;
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'btn-close btn-close-white ms-1 hb-tag-remove';
    remove.setAttribute('aria-label', 'Entfernen');
    chip.appendChild(remove);

    selector.querySelector('.hb-tag-field')?.insertBefore(chip, selector.querySelector('.hb-tag-input'));
    const option = selector.querySelector(`.hb-tag-option[data-tag-id="${tagId}"]`);
    if (option) option.classList.add('active');
  };

  const removeSelected = (selector, tagId) => {
    selector.querySelector(`.hb-tag-chip[data-tag-id="${tagId}"]`)?.remove();
    selector.querySelector(`.hb-tag-values input[value="${tagId}"]`)?.remove();
    const option = selector.querySelector(`.hb-tag-option[data-tag-id="${tagId}"]`);
    if (option) option.classList.remove('active');
  };

  selectors.forEach((selector) => {
    const input = selector.querySelector('.hb-tag-input');
    const field = selector.querySelector('.hb-tag-field');
    const menu = selector.querySelector('.hb-tag-dropdown');
    const toggle = selector.querySelector('[data-bs-toggle="dropdown"]');
    const dropdown = toggle ? bootstrap.Dropdown.getOrCreateInstance(toggle) : null;

    selector.dataset.selectorName = selector.querySelector('.hb-tag-values input')?.name || 'tag_ids[]';

    const openDropdown = () => {
      if (dropdown) dropdown.show();
      selector.classList.add('show');
      menu?.classList.add('show');
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
      filterOptions(event.target.value);
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
      });
    });

    selector.addEventListener('click', (event) => {
      if (event.target.classList.contains('hb-tag-remove')) {
        const chip = event.target.closest('.hb-tag-chip');
        if (chip) {
          removeSelected(selector, chip.dataset.tagId);
        }
      }
    });
  });

  document.addEventListener('click', (event) => {
    if (!event.target.closest('[data-tag-selector]') && !event.target.closest('.modal')) {
      closeAll();
    }
  });

  const modal = document.querySelector('.hb-tag-modal-form');
  if (!modal) return;

  modal.addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.target;
    const nameInput = form.querySelector('input[name="name"]');
    if (!nameInput || !nameInput.value.trim()) {
      nameInput?.classList.add('is-invalid');
      return;
    }
    nameInput.classList.remove('is-invalid');

    const colorInput = form.querySelector('input[name="color"]');
    const picker = form.querySelector('input[name="color_picker"]');
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
})();
