/**
 * Search and Sort Utilities
 * Client-side search and sort for tables
 */

class TableSearch {
    constructor(tableId, searchInputId) {
        this.table = document.getElementById(tableId);
        this.searchInput = document.getElementById(searchInputId);
        this.tbody = this.table ? this.table.querySelector('tbody') : null;
        this.rows = this.tbody ? Array.from(this.tbody.querySelectorAll('tr')) : [];

        if (this.searchInput) {
            this.searchInput.addEventListener('input', () => this.search());
        }
    }

    search() {
        const query = this.searchInput.value.toLowerCase().trim();

        this.rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            const match = text.includes(query);
            row.style.display = match ? '' : 'none';

            // Add highlight effect
            if (match && query.length > 0) {
                row.classList.add('search-match');
            } else {
                row.classList.remove('search-match');
            }
        });

        // Update result count
        const visibleCount = this.rows.filter(row => row.style.display !== 'none').length;
        this.updateResultCount(visibleCount);
    }

    updateResultCount(count) {
        let counter = document.getElementById('searchResultCount');
        if (!counter) {
            counter = document.createElement('div');
            counter.id = 'searchResultCount';
            counter.className = 'search-result-count';
            this.searchInput.parentElement.appendChild(counter);
        }

        if (this.searchInput.value.trim()) {
            counter.textContent = `${count} result${count !== 1 ? 's' : ''} found`;
            counter.style.display = 'block';
        } else {
            counter.style.display = 'none';
        }
    }

    clear() {
        this.searchInput.value = '';
        this.search();
    }
}

class TableSort {
    constructor(tableId) {
        this.table = document.getElementById(tableId);
        this.thead = this.table ? this.table.querySelector('thead') : null;
        this.tbody = this.table ? this.table.querySelector('tbody') : null;
        this.sortState = {}; // Track sort direction for each column

        if (this.thead) {
            this.initSortHeaders();
        }
    }

    initSortHeaders() {
        const headers = this.thead.querySelectorAll('th');
        headers.forEach((header, index) => {
            // Skip action columns
            if (header.textContent.toLowerCase().includes('aksi') ||
                header.textContent.toLowerCase().includes('action')) {
                return;
            }

            header.style.cursor = 'pointer';
            header.classList.add('sortable');
            header.setAttribute('data-column-index', index);

            // Add sort icon
            const icon = document.createElement('i');
            icon.className = 'fas fa-sort sort-icon';
            header.appendChild(icon);

            header.addEventListener('click', () => this.sort(index, header));
        });
    }

    sort(columnIndex, header) {
        const rows = Array.from(this.tbody.querySelectorAll('tr'));
        const currentDirection = this.sortState[columnIndex] || 'none';
        const newDirection = currentDirection === 'asc' ? 'desc' : 'asc';

        // Update sort state
        this.sortState = { [columnIndex]: newDirection };

        // Update icons
        this.thead.querySelectorAll('.sort-icon').forEach(icon => {
            icon.className = 'fas fa-sort sort-icon';
        });

        const icon = header.querySelector('.sort-icon');
        icon.className = `fas fa-sort-${newDirection === 'asc' ? 'up' : 'down'} sort-icon active`;

        // Sort rows
        rows.sort((a, b) => {
            const aValue = a.cells[columnIndex].textContent.trim();
            const bValue = b.cells[columnIndex].textContent.trim();

            // Try to parse as number
            const aNum = parseFloat(aValue.replace(/[^0-9.-]/g, ''));
            const bNum = parseFloat(bValue.replace(/[^0-9.-]/g, ''));

            let comparison = 0;
            if (!isNaN(aNum) && !isNaN(bNum)) {
                comparison = aNum - bNum;
            } else {
                comparison = aValue.localeCompare(bValue);
            }

            return newDirection === 'asc' ? comparison : -comparison;
        });

        // Re-append rows
        rows.forEach(row => this.tbody.appendChild(row));
    }
}

// Quick Filter Chips
class QuickFilters {
    constructor(containerId, tableId) {
        this.container = document.getElementById(containerId);
        this.table = document.getElementById(tableId);
        this.tbody = this.table ? this.table.querySelector('tbody') : null;
        this.rows = this.tbody ? Array.from(this.tbody.querySelectorAll('tr')) : [];

        if (this.container) {
            this.initFilters();
        }
    }

    initFilters() {
        const filters = this.container.querySelectorAll('.filter-chip');
        filters.forEach(chip => {
            chip.addEventListener('click', () => {
                // Remove active class from all
                filters.forEach(f => f.classList.remove('active'));
                // Add to clicked
                chip.classList.add('active');
                // Apply filter
                this.applyFilter(chip.dataset.filter);
            });
        });
    }

    applyFilter(filterType) {
        const now = new Date();
        const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());

        this.rows.forEach(row => {
            if (filterType === 'all') {
                row.style.display = '';
                return;
            }

            // Find date cell (usually first or second column)
            // Look for a cell with data-date attribute first
            let dateCell = row.querySelector('[data-date]') || row.cells[1] || row.cells[0];
            let dateValue = dateCell.getAttribute('data-date') || dateCell.textContent.trim();

            // If it's a timestamp, parse it
            let rowDate;
            if (/^\d+$/.test(dateValue)) {
                rowDate = new Date(parseInt(dateValue) * 1000); // PHP timestamp to JS Date
            } else {
                rowDate = new Date(dateValue);
            }

            if (isNaN(rowDate.getTime())) {
                console.error('Invalid date found in row:', dateValue);
                return;
            }

            // Reset time for comparison
            const rowDateMidnight = new Date(rowDate.getFullYear(), rowDate.getMonth(), rowDate.getDate());

            let show = false;

            switch (filterType) {
                case 'today':
                    show = rowDateMidnight.getTime() === today.getTime();
                    break;
                case 'week':
                    const weekAgo = new Date(today);
                    weekAgo.setDate(weekAgo.getDate() - 7);
                    show = rowDateMidnight >= weekAgo && rowDateMidnight <= today;
                    break;
                case 'month':
                    show = rowDateMidnight.getMonth() === today.getMonth() &&
                        rowDateMidnight.getFullYear() === today.getFullYear();
                    break;
            }

            row.style.display = show ? '' : 'none';
        });
    }
}

// Export Helper
function exportTableToCSV(url, params = {}) {
    // Build query string
    const queryString = new URLSearchParams(params).toString();
    const fullUrl = url + (queryString ? '?' + queryString : '');

    // Show loading
    LoadingSpinner.show('Preparing export...');

    // Create hidden iframe for download
    const iframe = document.createElement('iframe');
    iframe.style.display = 'none';
    iframe.src = fullUrl;
    document.body.appendChild(iframe);

    // Remove iframe and hide spinner after download starts
    setTimeout(() => {
        LoadingSpinner.hide();
        Toast.success('Export started! Check your downloads.');
        setTimeout(() => iframe.remove(), 1000);
    }, 1500);
}

// Add CSS for search and sort
if (!document.getElementById('search-sort-styles')) {
    const style = document.createElement('style');
    style.id = 'search-sort-styles';
    style.textContent = `
        .sortable {
            user-select: none;
            position: relative;
            padding-right: 25px !important;
        }
        
        .sort-icon {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            opacity: 0.3;
            font-size: 0.8em;
        }
        
        .sort-icon.active {
            opacity: 1;
            color: #3b82f6;
        }
        
        .sortable:hover .sort-icon {
            opacity: 0.6;
        }
        
        .search-match {
            background-color: #fef3c7 !important;
            animation: highlight 0.3s ease;
        }
        
        @keyframes highlight {
            from { background-color: #fbbf24; }
            to { background-color: #fef3c7; }
        }
        
        .search-result-count {
            margin-top: 8px;
            font-size: 0.875rem;
            color: #6b7280;
            font-weight: 500;
        }
        
        .filter-chip {
            display: inline-block;
            padding: 8px 16px;
            margin: 4px;
            border-radius: 20px;
            background: #f3f4f6;
            color: #4b5563;
            cursor: pointer;
            transition: all 0.2s;
            border: 2px solid transparent;
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        .filter-chip:hover {
            background: #e5e7eb;
        }
        
        .filter-chip.active {
            background: #3b82f6;
            color: white;
            border-color: #2563eb;
        }
        
        .search-box {
            position: relative;
            display: flex;
            align-items: center;
        }
        
        .search-box input {
            padding-left: 40px;
            padding-right: 40px;
        }
        
        .search-box i.fa-search {
            position: absolute;
            left: 12px;
            color: #9ca3af;
        }
        
        .search-box .clear-search {
            position: absolute;
            right: 12px;
            cursor: pointer;
            color: #9ca3af;
            display: none;
        }
        
        .search-box input:not(:placeholder-shown) ~ .clear-search {
            display: block;
        }
    `;
    document.head.appendChild(style);
}

// Export for use
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { TableSearch, TableSort, QuickFilters, exportTableToCSV };
}
