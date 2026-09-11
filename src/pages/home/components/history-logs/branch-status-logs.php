<section id="branchStatusLogsSection" class="branch-status-logs-section" aria-label="Branch Status Logs" style="display:none; padding:1rem">
    <h2 class="branch-status-logs-title">Branch Status Logs</h2>

    <form id="branchStatusLogsFilterForm" class="branch-status-logs-filter-card">
        <label class="branch-status-logs-field" for="branchStatusLogsSearch">
            <span>Search</span>
            <div class="branch-status-logs-autocomplete">
                <input
                    id="branchStatusLogsSearch"
                    name="branch"
                    type="search"
                    placeholder="Enter Branch ID or Branch Name"
                    autocomplete="off"
                    role="combobox"
                    aria-autocomplete="list"
                    aria-controls="branchStatusLogsSuggestions"
                    aria-expanded="false"
                >
                <ul id="branchStatusLogsSuggestions" role="listbox" hidden></ul>
            </div>
        </label>

        <div class="branch-status-logs-actions">
            <button id="branchStatusLogsDisplay" class="branch-status-logs-button branch-status-logs-button--primary" type="submit" disabled>
                Display
            </button>
            <button id="branchStatusLogsDisplayAll" class="branch-status-logs-button branch-status-logs-button--secondary" type="button">
                Display All
            </button>
        </div>
    </form>

    <div id="branchStatusLogsLoadingStatus" class="branch-status-logs-loading-status" role="status" aria-live="polite">
        Loading display for Branch status logs...
    </div>

    <div class="branch-status-logs-dropdown-filters" aria-label="Branch location filters">
        <label class="branch-status-logs-select-field" for="branchStatusLogsMainzone">
            <span>Mainzone</span>
            <select id="branchStatusLogsMainzone" name="mainzone">
                <option value="">Select Mainzone</option>
            </select>
        </label>

        <label class="branch-status-logs-select-field" for="branchStatusLogsZone">
            <span>Zone</span>
            <select id="branchStatusLogsZone" name="zone">
                <option value="">Select Zone</option>
            </select>
        </label>

        <label class="branch-status-logs-select-field" for="branchStatusLogsRegion">
            <span>Region</span>
            <select id="branchStatusLogsRegion" name="region">
                <option value="">Select Region</option>
            </select>
        </label>
    </div>

    <section class="branch-status-logs-table-card" aria-label="Branch status log results">
        <div class="branch-status-logs-table-wrap">
            <table id="branchStatusLogsTable" class="branch-status-logs-table">
                <thead>
                    <tr>
                        <th scope="col">Branch ID</th>
                        <th scope="col">Branch Name</th>
                        <th scope="col">Action</th>
                    </tr>
                </thead>
                <tbody id="branchStatusLogsTableBody">
                    <tr class="branch-status-logs-empty-row">
                        <td colspan="3">Use the filters above to display branch status logs.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</section>

<div id="branchStatusLogsDetailsModal" class="branch-status-logs-modal" hidden>
    <div class="branch-status-logs-modal__backdrop" data-branch-status-modal-close></div>
    <section
        class="branch-status-logs-modal__dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="branchStatusLogsDetailsTitle"
    >
        <header class="branch-status-logs-modal__header">
            <h3 id="branchStatusLogsDetailsTitle">Branch Status History Details</h3>
            <button
                id="branchStatusLogsDetailsClose"
                class="branch-status-logs-modal__close"
                type="button"
                aria-label="Close branch status history details"
                title="Close"
            >
                <span class="material-icons" aria-hidden="true">close</span>
            </button>
        </header>

        <div class="branch-status-logs-modal__body">
            <section class="branch-status-logs-modal__section" aria-labelledby="branchStatusLogsPresentDataTitle">
                <h4 id="branchStatusLogsPresentDataTitle">Present Data</h4>
                <dl class="branch-status-logs-modal__details">
                    <div>
                        <dt>Branch ID</dt>
                        <dd id="branchStatusLogsDetailsBranchId">—</dd>
                    </div>
                    <div>
                        <dt>Branch Name</dt>
                        <dd id="branchStatusLogsDetailsBranchName">—</dd>
                    </div>
                </dl>
            </section>

            <section class="branch-status-logs-modal__section" aria-labelledby="branchStatusLogsRecordedHistoryTitle">
                <h4 id="branchStatusLogsRecordedHistoryTitle">Recorded History Data</h4>
                <div class="branch-status-logs-modal__table-wrap">
                    <table class="branch-status-logs-modal__history-table">
                        <thead>
                            <tr>
                                <th scope="col">Posted Date</th>
                                <th scope="col">Branch ID</th>
                                <th scope="col">BOS Code</th>
                                <th scope="col">Branch Name</th>
                                <th scope="col">Area</th>
                                <th scope="col">Corporate Name</th>
                                <th scope="col">Mainzone</th>
                                <th scope="col">Zone</th>
                                <th scope="col">Region Name</th>
                                <th scope="col">Branch Status</th>
                                <th scope="col">Posted By</th>
                            </tr>
                        </thead>
                        <tbody id="branchStatusLogsHistoryTableBody">
                            <tr class="branch-status-logs-modal__empty-row">
                                <td colspan="11">Recorded history data will be displayed here.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </section>
</div>

<script>
(function () {
    const filterForm = document.getElementById('branchStatusLogsFilterForm');
    const searchInput = document.getElementById('branchStatusLogsSearch');
    const suggestions = document.getElementById('branchStatusLogsSuggestions');
    const displayButton = document.getElementById('branchStatusLogsDisplay');
    const displayAllButton = document.getElementById('branchStatusLogsDisplayAll');
    const loadingStatus = document.getElementById('branchStatusLogsLoadingStatus');
    const tableBody = document.getElementById('branchStatusLogsTableBody');
    const detailsModal = document.getElementById('branchStatusLogsDetailsModal');
    const detailsCloseButton = document.getElementById('branchStatusLogsDetailsClose');
    const detailsBranchId = document.getElementById('branchStatusLogsDetailsBranchId');
    const detailsBranchName = document.getElementById('branchStatusLogsDetailsBranchName');

    if (!filterForm || !searchInput || !suggestions || !displayButton ||
        !displayAllButton || !loadingStatus || !tableBody || !detailsModal ||
        !detailsCloseButton || !detailsBranchId || !detailsBranchName) return;

    let activeIndex = -1;
    let visibleBranches = [];
    let searchTimer = null;
    let requestController = null;
    let modalTrigger = null;

    document.body.appendChild(detailsModal);

    function updateButtonStates() {
        const hasSearchValue = searchInput.value.trim() !== '';
        displayButton.disabled = !hasSearchValue;
        displayAllButton.disabled = hasSearchValue;
    }

    function closeSuggestions() {
        suggestions.hidden = true;
        suggestions.innerHTML = '';
        searchInput.setAttribute('aria-expanded', 'false');
        searchInput.removeAttribute('aria-activedescendant');
        activeIndex = -1;
    }

    function chooseBranch(branch) {
        searchInput.value = branch.branch_id + ' — ' + branch.branch_name;
        searchInput.dataset.branchId = branch.branch_id;
        searchInput.dataset.branchName = branch.branch_name;
        updateButtonStates();
        closeSuggestions();
    }

    function setActiveOption(index) {
        const options = Array.from(suggestions.querySelectorAll('[role="option"]:not([aria-disabled="true"])'));
        options.forEach(function (option) { option.classList.remove('is-active'); });
        activeIndex = index;

        if (options[activeIndex]) {
            options[activeIndex].classList.add('is-active');
            options[activeIndex].scrollIntoView({ block: 'nearest' });
            searchInput.setAttribute('aria-activedescendant', options[activeIndex].id);
        }
    }

    function renderSuggestions(branches) {
        visibleBranches = branches;
        suggestions.innerHTML = '';
        activeIndex = -1;

        if (!branches.length) {
            const emptyOption = document.createElement('li');
            emptyOption.className = 'branch-status-logs-suggestion-empty';
            emptyOption.textContent = 'No matching branch found.';
            emptyOption.setAttribute('role', 'option');
            emptyOption.setAttribute('aria-disabled', 'true');
            suggestions.appendChild(emptyOption);
        } else {
            branches.forEach(function (branch, index) {
                const option = document.createElement('li');
                option.id = 'branchStatusLogsSuggestion-' + index;
                option.setAttribute('role', 'option');

                const branchId = document.createElement('strong');
                branchId.textContent = branch.branch_id;
                const branchName = document.createElement('span');
                branchName.textContent = branch.branch_name;
                option.append(branchId, branchName);

                option.addEventListener('mousedown', function (event) {
                    event.preventDefault();
                    chooseBranch(branch);
                });
                suggestions.appendChild(option);
            });
        }

        suggestions.hidden = false;
        searchInput.setAttribute('aria-expanded', 'true');
    }

    async function loadSuggestions() {
        const query = searchInput.value.trim();
        if (!query) {
            closeSuggestions();
            return;
        }

        if (requestController) requestController.abort();
        requestController = new AbortController();

        try {
            const endpoint = window.autoreconUrl('src/controllers/history-logs/branch-status-log-branches.php');
            const response = await fetch(endpoint + '?q=' + encodeURIComponent(query), {
                signal: requestController.signal,
                headers: { Accept: 'application/json' }
            });
            const payload = await response.json();
            if (!response.ok || !payload.success) throw new Error(payload.error || 'Search failed.');
            renderSuggestions(Array.isArray(payload.branches) ? payload.branches : []);
        } catch (error) {
            if (error.name !== 'AbortError') closeSuggestions();
        }
    }

    function renderTableRows(branches) {
        tableBody.innerHTML = '';

        if (!branches.length) {
            const row = document.createElement('tr');
            row.className = 'branch-status-logs-empty-row';
            const cell = document.createElement('td');
            cell.colSpan = 3;
            cell.textContent = 'No branch status records found.';
            row.appendChild(cell);
            tableBody.appendChild(row);
            return;
        }

        branches.forEach(function (branch) {
            const row = document.createElement('tr');
            const idCell = document.createElement('td');
            const nameCell = document.createElement('td');
            const actionCell = document.createElement('td');
            const viewButton = document.createElement('button');

            idCell.textContent = branch.branch_id;
            nameCell.textContent = branch.branch_name;
            viewButton.type = 'button';
            viewButton.className = 'branch-status-logs-view-button';
            viewButton.title = 'View Branch status history details';
            viewButton.setAttribute('aria-label', 'View Branch status history details');
            viewButton.dataset.branchId = branch.branch_id;
            viewButton.dataset.branchName = branch.branch_name;
            const viewIcon = document.createElement('span');
            viewIcon.className = 'material-icons-outlined';
            viewIcon.setAttribute('aria-hidden', 'true');
            viewIcon.textContent = 'visibility';
            viewButton.appendChild(viewIcon);
            actionCell.appendChild(viewButton);
            row.append(idCell, nameCell, actionCell);
            tableBody.appendChild(row);
        });
    }

    async function displayBranches(showAll) {
        closeSuggestions();
        displayButton.disabled = true;
        displayAllButton.disabled = true;
        loadingStatus.textContent = 'Loading display for Branch status logs...';

        const params = new URLSearchParams();
        if (showAll) {
            params.set('all', '1');
        } else {
            const query = searchInput.dataset.branchId || searchInput.value.trim();
            if (!query) {
                updateButtonStates();
                return;
            }
            params.set('q', query);
            params.set('all', '1');
        }

        try {
            const endpoint = window.autoreconUrl('src/controllers/history-logs/branch-status-log-branches.php');
            const response = await fetch(endpoint + '?' + params.toString(), {
                headers: { Accept: 'application/json' }
            });
            const payload = await response.json();
            if (!response.ok || !payload.success) {
                throw new Error(payload.error || 'Unable to display branch status logs.');
            }

            const branches = Array.isArray(payload.branches) ? payload.branches : [];
            renderTableRows(branches);
            loadingStatus.textContent = branches.length
                ? branches.length + (branches.length === 1 ? ' branch displayed.' : ' branches displayed.')
                : 'No branch status records found.';
        } catch (error) {
            renderTableRows([]);
            loadingStatus.textContent = error.message || 'Unable to display branch status logs.';
        } finally {
            updateButtonStates();
        }
    }

    function openDetailsModal(trigger) {
        modalTrigger = trigger;
        detailsBranchId.textContent = trigger.dataset.branchId || '—';
        detailsBranchName.textContent = trigger.dataset.branchName || '—';
        detailsModal.hidden = false;
        document.body.classList.add('branch-status-logs-modal-open');
        detailsCloseButton.focus();
    }

    function closeDetailsModal() {
        if (detailsModal.hidden) return;
        detailsModal.hidden = true;
        document.body.classList.remove('branch-status-logs-modal-open');
        if (modalTrigger && document.contains(modalTrigger)) modalTrigger.focus();
        modalTrigger = null;
    }

    searchInput.addEventListener('input', function () {
        delete searchInput.dataset.branchId;
        delete searchInput.dataset.branchName;
        updateButtonStates();
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(loadSuggestions, 200);
    });
    searchInput.addEventListener('search', updateButtonStates);
    searchInput.addEventListener('keydown', function (event) {
        if (event.key === 'ArrowDown' && visibleBranches.length) {
            event.preventDefault();
            setActiveOption((activeIndex + 1) % visibleBranches.length);
        } else if (event.key === 'ArrowUp' && visibleBranches.length) {
            event.preventDefault();
            setActiveOption((activeIndex - 1 + visibleBranches.length) % visibleBranches.length);
        } else if (event.key === 'Enter' && activeIndex >= 0) {
            event.preventDefault();
            chooseBranch(visibleBranches[activeIndex]);
        } else if (event.key === 'Escape') {
            closeSuggestions();
        }
    });
    document.addEventListener('click', function (event) {
        if (!event.target.closest('.branch-status-logs-autocomplete')) closeSuggestions();
    });
    filterForm.addEventListener('submit', function (event) {
        event.preventDefault();
        displayBranches(false);
    });
    displayAllButton.addEventListener('click', function () {
        displayBranches(true);
    });
    tableBody.addEventListener('click', function (event) {
        const viewButton = event.target.closest('.branch-status-logs-view-button');
        if (viewButton) openDetailsModal(viewButton);
    });
    detailsCloseButton.addEventListener('click', closeDetailsModal);
    detailsModal.addEventListener('click', function (event) {
        if (event.target.hasAttribute('data-branch-status-modal-close')) closeDetailsModal();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !detailsModal.hidden) closeDetailsModal();
    });
    updateButtonStates();

}());
</script>
