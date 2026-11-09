/**
 * Garment Manufacturing ERP - Core Frontend Scripts (Native Vanilla JS)
 */

document.addEventListener('DOMContentLoaded', function() {
    // Mobile sidebar toggle
    const sidebar = document.querySelector('.app-sidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    const toggles = document.querySelectorAll('#sidebarToggle, #sidebarMobileClose');

    toggles.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            if (sidebar) sidebar.classList.toggle('show');
            if (backdrop) backdrop.classList.toggle('d-none');
        });
    });

    if (backdrop) {
        backdrop.addEventListener('click', function() {
            if (sidebar) sidebar.classList.remove('show');
            this.classList.add('d-none');
        });
    }

    // Auto-dismiss alerts after 5 seconds
    setTimeout(function() {
        document.querySelectorAll('.alert-dismissible').forEach(alert => {
            alert.style.transition = 'opacity 0.5s ease';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 500);
        });
    }, 5000);

    // Number formatting helper
    window.formatMoney = function(amount) {
        return 'Rs. ' + parseFloat(amount || 0).toLocaleString('en-PK', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    };

    // Print helper
    document.querySelectorAll('.btn-print-trigger').forEach(btn => {
        btn.addEventListener('click', () => window.print());
    });

    // Hash-based Tab Switching
    function activateTabFromHash() {
        const hash = window.location.hash;
        if (hash) {
            const cleanHash = hash.replace('#', '');
            const targetButton = document.querySelector(`[data-bs-target="#${cleanHash}Tab"]`) || 
                                 document.querySelector(`[data-bs-target="#${cleanHash}"]`) ||
                                 document.querySelector(`[href="#${cleanHash}"]`);
            if (targetButton && window.bootstrap && bootstrap.Tab) {
                bootstrap.Tab.getOrCreateInstance(targetButton).show();
            }
        }
    }
    activateTabFromHash();
    window.addEventListener('hashchange', activateTabFromHash);

    // Initialize System-Wide Searchable Select Boxes (Lots & Key Entities)
    initSearchableSelects();
});

/**
 * Universal Searchable Select Component for Lots, Workers/Operators, Operations & Dropdowns across the system
 */
function initSearchableSelects(container = document) {
    const selectors = [
        // Lots
        'select[name="lot_id"]',
        'select#pe_lot_id',
        'select#return_lot_id',
        'select.lot-select',
        'select.searchable-lot',

        // Workers & Operators
        'select[name="worker_id"]',
        'select[name="operator_id"]',
        'select[name="night_operator_id"]',
        'select[name="day_worker_id"]',
        'select[name="night_worker_id"]',
        'select[name="cutting_worker_id"]',
        'select#pe_worker_id',
        'select#cut_worker_id',
        'select#op_single_day_worker',
        'select#op_double_day_worker',
        'select#op_double_night_worker',
        'select#workerSelect',
        'select.worker-select',
        'select.operator-select',

        // Operations & Stages
        'select[name="operation_id"]',
        'select#pe_operation_id',
        'select.operation-select',

        // Explicit opt-in
        'select.searchable-select',
        'select[data-searchable="true"]'
    ].join(', ');

    const selects = container.querySelectorAll(selectors);

    selects.forEach(select => {
        // Skip already initialized, explicitly opted-out, or static config selects
        if (select.dataset.searchableInit === 'true' || select.dataset.searchable === 'false') {
            return;
        }
        if (select.name === 'worker_type' || select.id === 'wrk_type') {
            return;
        }
        select.dataset.searchableInit = 'true';

        // Detect Entity Category
        let entityType = 'generic';
        const nameOrId = ((select.name || '') + ' ' + (select.id || '') + ' ' + (select.className || '')).toLowerCase();
        if (nameOrId.includes('lot')) {
            entityType = 'lot';
        } else if (nameOrId.includes('worker') || nameOrId.includes('operator') || nameOrId.includes('artisan') || nameOrId.includes('cutter')) {
            entityType = 'worker';
        } else if (nameOrId.includes('operation') || nameOrId.includes('stage')) {
            entityType = 'operation';
        }

        let searchPlaceholder = '🔍 Type to filter options...';
        let emptyNoticeHtml = '<i class="bi bi-search me-1"></i> No matching options found';
        let defaultPlaceholder = '-- Select Option --';

        if (entityType === 'lot') {
            searchPlaceholder = '🔍 Type lot # or product to filter...';
            emptyNoticeHtml = '<i class="bi bi-inbox me-1"></i> No matching lots found';
            defaultPlaceholder = '-- Select Lot --';
        } else if (entityType === 'worker') {
            searchPlaceholder = '🔍 Type worker name, code (WRK-), or wage type...';
            emptyNoticeHtml = '<i class="bi bi-person-x me-1"></i> No matching workers/operators found';
            defaultPlaceholder = '-- Select Worker / Operator --';
        } else if (entityType === 'operation') {
            searchPlaceholder = '🔍 Type stage name, #, or dept to filter...';
            emptyNoticeHtml = '<i class="bi bi-gear me-1"></i> No matching operations found';
            defaultPlaceholder = '-- Select Operation / Stage --';
        }

        const isSm = select.classList.contains('form-select-sm') || select.classList.contains('input-sm');
        let placeholder = select.getAttribute('placeholder') || defaultPlaceholder;
        if (select.options.length > 0 && select.options[0].value === '') {
            placeholder = select.options[0].textContent.trim();
        }

        // Create Wrapper
        const wrapper = document.createElement('div');
        wrapper.className = 'searchable-lot-wrapper position-relative';

        // Hide native select offscreen while maintaining form submit and DOM accessibility
        select.classList.add('searchable-native-hidden');

        // Create Trigger Button
        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'btn searchable-lot-trigger form-select text-start d-flex justify-content-between align-items-center w-100 ' + 
                            (isSm ? 'form-select-sm ' : '');
        if (select.classList.contains('fw-bold')) trigger.classList.add('fw-bold');
        if (select.classList.contains('fw-semibold')) trigger.classList.add('fw-semibold');
        if (select.classList.contains('text-dark')) trigger.classList.add('text-dark');
        trigger.setAttribute('aria-expanded', 'false');

        const labelSpan = document.createElement('span');
        labelSpan.className = 'searchable-lot-label text-truncate me-2';
        
        const chevron = document.createElement('i');
        chevron.className = 'bi bi-chevron-down text-muted small ms-auto flex-shrink-0';
        
        trigger.appendChild(labelSpan);
        trigger.appendChild(chevron);

        // Create Dropdown Menu
        const dropdown = document.createElement('div');
        dropdown.className = 'searchable-lot-dropdown dropdown-menu p-2 shadow-lg border';
        dropdown.style.display = 'none';
        dropdown.style.position = 'absolute';
        dropdown.style.top = '100%';
        dropdown.style.left = '0';

        // Search Input Container
        const searchBox = document.createElement('div');
        searchBox.className = 'position-relative mb-2';
        searchBox.innerHTML = `
            <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-2 text-muted small" style="pointer-events: none;"></i>
            <input type="text" class="form-control form-control-sm ps-4 searchable-lot-search-input" placeholder="${searchPlaceholder}" autocomplete="off">
        `;
        const searchInput = searchBox.querySelector('input');

        // Options List
        const listContainer = document.createElement('div');
        listContainer.className = 'searchable-lot-list';

        // Empty Search Notice
        const emptyNotice = document.createElement('div');
        emptyNotice.className = 'searchable-lot-empty text-center text-muted small py-3 d-none';
        emptyNotice.innerHTML = emptyNoticeHtml;

        dropdown.appendChild(searchBox);
        dropdown.appendChild(listContainer);
        dropdown.appendChild(emptyNotice);

        // Insert into DOM
        select.parentNode.insertBefore(wrapper, select);
        wrapper.appendChild(select);
        wrapper.appendChild(trigger);
        wrapper.appendChild(dropdown);

        function updateTriggerLabel() {
            if (select.options.length > 0 && select.options[0].value === '') {
                placeholder = select.options[0].textContent.trim();
            }
            const selectedOpt = select.options[select.selectedIndex];
            if (selectedOpt && selectedOpt.value !== '') {
                labelSpan.textContent = selectedOpt.textContent.trim();
                labelSpan.classList.remove('text-muted');
                trigger.classList.add('has-value');
            } else {
                labelSpan.textContent = placeholder;
                labelSpan.classList.add('text-muted');
                trigger.classList.remove('has-value');
            }
        }

        function buildOptionsList() {
            listContainer.innerHTML = '';
            const opts = Array.from(select.options);

            opts.forEach((opt, idx) => {
                const item = document.createElement('div');
                item.className = 'searchable-lot-item';
                item.dataset.value = opt.value;
                item.dataset.index = idx;
                item.dataset.text = (opt.textContent || '').trim().toLowerCase();

                const isSelected = (opt.value === select.value) || (select.selectedIndex === idx && opt.value !== '');
                if (isSelected && opt.value !== '') {
                    item.classList.add('active');
                }

                const rawText = opt.textContent.trim();
                if (opt.value === '') {
                    item.innerHTML = `<span class="text-muted fst-italic">${rawText}</span>`;
                } else if (entityType === 'lot') {
                    const lotMatch = rawText.match(/^(LOT\s*#?[^—\-\(\[\s]+)(.*)$/i);
                    if (lotMatch) {
                        const lotBadge = lotMatch[1].trim();
                        const remainder = lotMatch[2].replace(/^[\s—\-]+/, '').trim();
                        item.innerHTML = `
                            <div class="d-flex align-items-center gap-2 text-truncate w-100">
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle lot-badge flex-shrink-0">${lotBadge}</span>
                                <span class="text-truncate flex-grow-1">${remainder}</span>
                            </div>
                        `;
                    } else {
                        item.innerHTML = `<span class="text-truncate">${rawText}</span>`;
                    }
                } else if (entityType === 'worker') {
                    const wrkMatch = rawText.match(/^([^\(]+?)(?:\s*\(([^)]+)\))?(.*)$/);
                    if (wrkMatch && wrkMatch[2]) {
                        const name = wrkMatch[1].trim();
                        const code = wrkMatch[2].trim();
                        const details = (wrkMatch[3] || '').trim().replace(/^[\s—\-]+/, '');
                        
                        let detailsHtml = '';
                        if (details.includes('Minute Rate')) {
                            detailsHtml = `<span class="badge bg-success-subtle text-success border small flex-shrink-0 ms-auto">${details}</span>`;
                        } else if (details.includes('Piece Rate')) {
                            detailsHtml = `<span class="badge bg-primary-subtle text-primary border small flex-shrink-0 ms-auto">${details}</span>`;
                        } else if (details) {
                            detailsHtml = `<span class="small text-muted flex-shrink-0 ms-auto">${details}</span>`;
                        }

                        item.innerHTML = `
                            <div class="d-flex align-items-center gap-2 text-truncate w-100">
                                <span class="badge bg-light text-dark border small flex-shrink-0 worker-code-badge font-monospace">${code}</span>
                                <span class="fw-semibold text-truncate flex-grow-1">${name}</span>
                                ${detailsHtml}
                            </div>
                        `;
                    } else {
                        item.innerHTML = `<span class="text-truncate">${rawText}</span>`;
                    }
                } else if (entityType === 'operation') {
                    const opMatch = rawText.match(/^(\s*↳?\s*\d+\.?)\s*(.*)$/);
                    if (opMatch) {
                        const seq = opMatch[1].trim();
                        const remainder = opMatch[2].trim();
                        item.innerHTML = `
                            <div class="d-flex align-items-center gap-2 text-truncate w-100">
                                <span class="badge bg-primary-subtle text-primary border small flex-shrink-0 op-seq-badge font-monospace">${seq}</span>
                                <span class="text-truncate flex-grow-1">${remainder}</span>
                            </div>
                        `;
                    } else {
                        item.innerHTML = `<span class="text-truncate">${rawText}</span>`;
                    }
                } else {
                    item.innerHTML = `<span class="text-truncate">${rawText}</span>`;
                }

                item.addEventListener('click', function(e) {
                    e.stopPropagation();
                    chooseOption(opt.value);
                });

                listContainer.appendChild(item);
            });

            updateTriggerLabel();
        }

        function chooseOption(val) {
            select.value = val;
            updateTriggerLabel();

            listContainer.querySelectorAll('.searchable-lot-item').forEach(it => {
                it.classList.toggle('active', it.dataset.value === String(val));
            });

            closeDropdown();
            trigger.classList.remove('is-invalid');

            // Dispatch events to trigger any attached listeners
            select.dispatchEvent(new Event('input', { bubbles: true }));
            select.dispatchEvent(new Event('change', { bubbles: true }));

            // Support inline onchange
            if (typeof select.onchange === 'function') {
                select.onchange.call(select, new Event('change'));
            }
        }

        function openDropdown() {
            if (select.disabled) return;

            document.querySelectorAll('.searchable-lot-dropdown').forEach(d => {
                if (d !== dropdown) d.style.display = 'none';
            });
            document.querySelectorAll('.searchable-lot-trigger').forEach(t => {
                if (t !== trigger) {
                    t.classList.remove('show');
                    t.setAttribute('aria-expanded', 'false');
                }
            });

            dropdown.style.display = 'block';
            trigger.classList.add('show');
            trigger.setAttribute('aria-expanded', 'true');

            // Prevent dropdown from overflowing right edge of viewport
            dropdown.style.left = '0';
            dropdown.style.right = 'auto';
            const rect = dropdown.getBoundingClientRect();
            if (rect.right > window.innerWidth - 10) {
                dropdown.style.left = 'auto';
                dropdown.style.right = '0';
            }

            searchInput.value = '';
            filterItems('');
            setTimeout(() => searchInput.focus(), 60);

            updateTriggerLabel();
            const activeItem = listContainer.querySelector('.searchable-lot-item.active');
            if (activeItem) {
                activeItem.scrollIntoView({ block: 'nearest' });
            }
        }

        function closeDropdown() {
            dropdown.style.display = 'none';
            trigger.classList.remove('show');
            trigger.setAttribute('aria-expanded', 'false');
        }

        function filterItems(query) {
            const q = query.trim().toLowerCase();
            const items = listContainer.querySelectorAll('.searchable-lot-item');
            let visibleCount = 0;

            items.forEach(it => {
                const text = it.dataset.text || '';
                const matches = (q === '') || text.includes(q);
                if (matches) {
                    it.style.display = 'flex';
                    visibleCount++;
                } else {
                    it.style.display = 'none';
                }
                it.classList.remove('highlighted');
            });

            if (visibleCount === 0) {
                emptyNotice.classList.remove('d-none');
            } else {
                emptyNotice.classList.add('d-none');
            }
        }

        trigger.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            if (dropdown.style.display === 'block') {
                closeDropdown();
            } else {
                openDropdown();
            }
        });

        searchInput.addEventListener('input', function() {
            filterItems(this.value);
        });

        // Keyboard navigation inside search input
        searchInput.addEventListener('keydown', function(e) {
            const visibleItems = Array.from(listContainer.querySelectorAll('.searchable-lot-item')).filter(it => it.style.display !== 'none');
            const highlighted = listContainer.querySelector('.searchable-lot-item.highlighted');
            let currentIndex = highlighted ? visibleItems.indexOf(highlighted) : -1;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (visibleItems.length > 0) {
                    if (highlighted) highlighted.classList.remove('highlighted');
                    currentIndex = (currentIndex + 1) % visibleItems.length;
                    visibleItems[currentIndex].classList.add('highlighted');
                    visibleItems[currentIndex].scrollIntoView({ block: 'nearest' });
                }
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (visibleItems.length > 0) {
                    if (highlighted) highlighted.classList.remove('highlighted');
                    currentIndex = (currentIndex - 1 + visibleItems.length) % visibleItems.length;
                    visibleItems[currentIndex].classList.add('highlighted');
                    visibleItems[currentIndex].scrollIntoView({ block: 'nearest' });
                }
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (highlighted) {
                    highlighted.click();
                } else if (visibleItems.length > 0) {
                    visibleItems[0].click();
                }
            } else if (e.key === 'Escape') {
                e.preventDefault();
                closeDropdown();
                trigger.focus();
            }
        });

        trigger.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown') {
                e.preventDefault();
                openDropdown();
            }
        });

        // When original select value changes programmatically
        select.addEventListener('change', function() {
            updateTriggerLabel();
            listContainer.querySelectorAll('.searchable-lot-item').forEach(it => {
                it.classList.toggle('active', it.dataset.value === String(select.value));
            });
        });

        // Form validation feedback
        select.addEventListener('invalid', function() {
            trigger.classList.add('is-invalid');
            trigger.focus();
        });

        // Dynamic mutation observer for options
        const observer = new MutationObserver(function() {
            buildOptionsList();
        });
        observer.observe(select, { childList: true });

        // Intercept property setters so programmatic changes (.value = ..., .selectedIndex = ...) automatically sync UI
        try {
            const origValueDesc = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value');
            if (origValueDesc && origValueDesc.set) {
                Object.defineProperty(select, 'value', {
                    get: function() {
                        return origValueDesc.get.call(this);
                    },
                    set: function(newVal) {
                        origValueDesc.set.call(this, newVal);
                        updateTriggerLabel();
                        listContainer.querySelectorAll('.searchable-lot-item').forEach(it => {
                            it.classList.toggle('active', it.dataset.value === String(newVal));
                        });
                    },
                    configurable: true
                });
            }

            const origIndexDesc = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'selectedIndex');
            if (origIndexDesc && origIndexDesc.set) {
                Object.defineProperty(select, 'selectedIndex', {
                    get: function() {
                        return origIndexDesc.get.call(this);
                    },
                    set: function(newIdx) {
                        origIndexDesc.set.call(this, newIdx);
                        updateTriggerLabel();
                        const currentVal = this.value;
                        listContainer.querySelectorAll('.searchable-lot-item').forEach(it => {
                            it.classList.toggle('active', it.dataset.value === String(currentVal));
                        });
                    },
                    configurable: true
                });
            }

            const origDisabledDesc = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'disabled');
            if (origDisabledDesc && origDisabledDesc.set) {
                Object.defineProperty(select, 'disabled', {
                    get: function() {
                        return origDisabledDesc.get.call(this);
                    },
                    set: function(val) {
                        origDisabledDesc.set.call(this, val);
                        trigger.disabled = !!val;
                        if (val) trigger.classList.add('disabled');
                        else trigger.classList.remove('disabled');
                    },
                    configurable: true
                });
            }

            if (select.disabled) {
                trigger.disabled = true;
                trigger.classList.add('disabled');
            }

            const origFocus = select.focus;
            select.focus = function(options) {
                if (trigger) trigger.focus(options);
                else if (origFocus) origFocus.call(this, options);
            };
        } catch (e) {
            console.debug('Searchable select property descriptor hook skipped:', e);
        }

        // Initial build
        buildOptionsList();
    });

    // Global outside click handler
    if (!window._searchableLotGlobalClickBound) {
        window._searchableLotGlobalClickBound = true;
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.searchable-lot-wrapper')) {
                document.querySelectorAll('.searchable-lot-dropdown').forEach(d => d.style.display = 'none');
                document.querySelectorAll('.searchable-lot-trigger').forEach(t => {
                    t.classList.remove('show');
                    t.setAttribute('aria-expanded', 'false');
                });
            }
        });
    }
}
window.initSearchableSelects = initSearchableSelects;

/**
 * Toast notifications
 */
function showToast(message, type = 'success') {
    const icon = type === 'success' ? 'bi-check-circle-fill text-success' : 
                 type === 'danger' ? 'bi-exclamation-triangle-fill text-danger' : 
                 type === 'warning' ? 'bi-exclamation-circle-fill text-warning' : 'bi-info-circle-fill text-primary';
                 
    let container = document.getElementById('toastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toastContainer';
        container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
        container.style.zIndex = '1090';
        document.body.appendChild(container);
    }

    const toastEl = document.createElement('div');
    toastEl.className = 'toast align-items-center text-bg-light border-0 shadow-lg mb-2';
    toastEl.setAttribute('role', 'alert');
    toastEl.setAttribute('aria-live', 'assertive');
    toastEl.setAttribute('aria-atomic', 'true');
    toastEl.setAttribute('data-bs-delay', '4000');
    toastEl.innerHTML = `
        <div class="d-flex">
            <div class="toast-body d-flex align-items-center gap-2">
                <i class="bi ${icon} fs-5"></i>
                <span class="fw-medium">${message}</span>
            </div>
            <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    `;
    container.appendChild(toastEl);
    if (window.bootstrap && bootstrap.Toast) {
        const bsToast = new bootstrap.Toast(toastEl);
        bsToast.show();
        toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
    }
}
