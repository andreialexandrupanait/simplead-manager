@once
<script>
window.dataTableMixin = function(rows, filterFields) {
    return {
        rows: rows,
        limit: 10,
        search: '',
        sortCol: null,
        sortDir: 'desc',
        get sorted() {
            if (!this.sortCol) return this.rows;
            const col = this.sortCol, dir = this.sortDir;
            return [...this.rows].sort((a, b) => {
                let av = a[col], bv = b[col];
                if (typeof av === 'string') { av = av.toLowerCase(); bv = bv.toLowerCase(); }
                if (av < bv) return dir === 'asc' ? -1 : 1;
                if (av > bv) return dir === 'asc' ? 1 : -1;
                return 0;
            });
        },
        get filtered() {
            if (!this.search) return this.sorted;
            const q = this.search.toLowerCase();
            return this.sorted.filter(r => filterFields.some(f => String(r[f] || '').toLowerCase().includes(q)));
        },
        get total() { return this.filtered.length; },
        toggleSort(col) {
            if (this.sortCol === col) { this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc'; }
            else { this.sortCol = col; this.sortDir = 'desc'; }
        },
        sortIcon(col) {
            if (this.sortCol !== col) return '\u21D5';
            return this.sortDir === 'asc' ? '\u2191' : '\u2193';
        },
        csvEscape(v) { return '"' + String(v).replace(/"/g, '""') + '"'; },
        downloadCsv(csv, name) {
            const blob = new Blob([csv], { type: 'text/csv' });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = name;
            a.click();
            URL.revokeObjectURL(a.href);
        },
    };
};
</script>
@endonce
