/**
 * SimpleAd Manager — Admin Interface
 * Vanilla JS IIFE. No external dependencies.
 */
(function () {
    'use strict';

    /* ──────────── helpers ──────────── */

    function ajax(action, data, method) {
        method = method || 'POST';
        var body = new URLSearchParams();
        body.append('action', action);
        body.append('nonce', samAdmin.nonce);
        if (data) {
            Object.keys(data).forEach(function (k) {
                body.append(k, data[k]);
            });
        }
        return fetch(samAdmin.ajaxUrl, {
            method: method === 'GET' ? 'POST' : 'POST', // WP AJAX always POST
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body,
            credentials: 'same-origin'
        }).then(function (r) { return r.json(); });
    }

    function escHtml(s) {
        if (s === null || s === undefined) return '';
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(String(s)));
        return d.innerHTML;
    }

    function formatBytes(b) {
        if (!b || b === 0) return '0 B';
        var u = ['B', 'KB', 'MB', 'GB', 'TB'];
        var i = Math.floor(Math.log(b) / Math.log(1024));
        return (b / Math.pow(1024, i)).toFixed(i > 0 ? 1 : 0) + ' ' + u[i];
    }

    function formatNumber(n) {
        if (n === null || n === undefined) return '0';
        return Number(n).toLocaleString();
    }

    function badge(text, type) {
        return '<span class="sam-badge sam-badge-' + type + '">' + escHtml(text) + '</span>';
    }

    function progressBar(pct, label) {
        var color = pct >= 80 ? 'red' : pct >= 60 ? 'yellow' : 'green';
        return '<div class="sam-progress-wrap"><div class="sam-progress-bar sam-progress-' + color + '" style="width:' + Math.min(pct, 100) + '%">' + (label || Math.round(pct) + '%') + '</div></div>';
    }

    function scoreBar(pct) {
        var color = pct >= 80 ? 'green' : pct >= 50 ? 'yellow' : 'red';
        return '<div class="sam-progress-wrap"><div class="sam-progress-bar sam-progress-' + color + '" style="width:' + pct + '%">' + pct + '%</div></div>';
    }

    function loading() {
        return '<div class="sam-loading"><span class="spinner is-active"></span> Loading&hellip;</div>';
    }

    function errorBox(msg) {
        return '<div class="sam-error">' + escHtml(msg) + '</div>';
    }

    function $(sel, ctx) { return (ctx || document).querySelector(sel); }
    function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

    /* keep track of which tabs have been loaded */
    var loaded = {};

    /* ──────────── tab system ──────────── */

    function initTabs() {
        var tabs = $$('.sam-nav-tab');
        tabs.forEach(function (tab) {
            tab.addEventListener('click', function (e) {
                e.preventDefault();
                var target = tab.getAttribute('data-tab');
                activateTab(target);
            });
        });

        // Restore tab from hash
        var hash = window.location.hash.replace('#', '');
        if (hash && document.getElementById('sam-pane-' + hash)) {
            activateTab(hash);
        } else {
            activateTab('dashboard');
        }
    }

    function activateTab(name) {
        // Update nav
        $$('.sam-nav-tab').forEach(function (t) {
            t.classList.toggle('nav-tab-active', t.getAttribute('data-tab') === name);
        });
        // Update panes
        $$('.sam-tab-pane').forEach(function (p) {
            p.classList.toggle('active', p.id === 'sam-pane-' + name);
        });
        // Update hash
        history.replaceState(null, '', '#' + name);
        // Load if first time
        if (!loaded[name]) {
            loaded[name] = true;
            loadTab(name);
        }
    }

    function loadTab(name) {
        var loaders = {
            dashboard: loadDashboard,
            security: loadSecurity,
            database: loadDatabase,
            cron: loadCron,
            server: loadServer,
            audit: loadAudit,
            firewall: loadFirewall,
            woocommerce: loadWooCommerce
        };
        if (loaders[name]) loaders[name]();
    }

    function reloadTab(name) {
        loaded[name] = false;
        var pane = document.getElementById('sam-pane-' + name);
        if (pane) {
            // Don't clear connection tab
            if (name !== 'connection') pane.innerHTML = loading();
        }
        loaded[name] = true;
        loadTab(name);
    }

    /* ──────────── Dashboard ──────────── */

    function loadDashboard() {
        var pane = $('#sam-pane-dashboard');
        pane.innerHTML = loading();

        Promise.all([
            ajax('sam_health_check'),
            ajax('sam_server_resources'),
            ajax('sam_site_info')
        ]).then(function (results) {
            var health = results[0].data || results[0];
            var resources = results[1].data || results[1];
            var info = results[2].data || results[2];
            var html = '';

            // Quick stats cards
            html += '<div class="sam-cards">';
            html += dashCard('WordPress', escHtml(info.wp_version || 'N/A'), info.core_update_available ? 'Update available' : 'Up to date');
            html += dashCard('PHP Version', escHtml(info.php_version || 'N/A'), escHtml(info.server_software || ''));
            html += dashCard('Active Plugins', formatNumber(info.active_plugins_count !== undefined ? info.active_plugins_count : '?'), '');
            html += dashCard('Database', escHtml(info.mysql_version || 'N/A'), '');
            html += '</div>';

            // Health checks summary
            if (health.checks) {
                html += '<h3 class="sam-section-title">Health Status</h3>';
                html += '<div class="sam-cards">';
                html += healthCard('Database', health.checks.database_connected !== undefined ? health.checks.database_connected : true);
                html += healthCard('Uploads Writable', health.checks.uploads_writable !== undefined ? health.checks.uploads_writable : true);
                html += healthCard('SSL Active', health.checks.ssl_active !== undefined ? health.checks.ssl_active : false);
                html += healthCard('WP Cron', health.checks.cron_enabled !== undefined ? health.checks.cron_enabled : true);
                html += '</div>';
            }

            // Server resources
            if (resources.cpu !== undefined || resources.memory) {
                html += '<h3 class="sam-section-title">Server Resources</h3>';
                html += '<div style="max-width:600px">';
                if (resources.cpu !== undefined) {
                    html += resourceRow('CPU Usage', resources.cpu);
                }
                if (resources.memory) {
                    var memPct = resources.memory.total > 0 ? Math.round((resources.memory.used / resources.memory.total) * 100) : 0;
                    html += resourceRow('Memory', memPct, formatBytes(resources.memory.used) + ' / ' + formatBytes(resources.memory.total));
                }
                if (resources.disk) {
                    var diskPct = resources.disk.total > 0 ? Math.round((resources.disk.used / resources.disk.total) * 100) : 0;
                    html += resourceRow('Disk', diskPct, formatBytes(resources.disk.used) + ' / ' + formatBytes(resources.disk.total));
                }
                html += '</div>';
            }

            html += '<div class="sam-actions"><button class="button" onclick="samReload(\'dashboard\')">Refresh</button></div>';
            pane.innerHTML = html;

        }).catch(function (err) {
            pane.innerHTML = errorBox('Failed to load dashboard: ' + err.message);
        });
    }

    function dashCard(title, value, sub) {
        return '<div class="sam-card"><h3>' + escHtml(title) + '</h3><div class="sam-card-value">' + value + '</div>' + (sub ? '<div class="sam-card-sub">' + escHtml(sub) + '</div>' : '') + '</div>';
    }

    function healthCard(label, val) {
        var ok = val === true || val === 1;
        return '<div class="sam-card"><h3>' + escHtml(label) + '</h3>' + badge(ok ? 'OK' : 'Issue', ok ? 'pass' : 'fail') + '</div>';
    }

    function resourceRow(label, pct, detail) {
        return '<div class="sam-resource-row"><label>' + escHtml(label) + ' (' + Math.round(pct) + '%)</label>' + progressBar(pct) + (detail ? '<div class="sam-resource-detail">' + escHtml(detail) + '</div>' : '') + '</div>';
    }

    /* ──────────── Security ──────────── */

    function loadSecurity() {
        var pane = $('#sam-pane-security');
        pane.innerHTML = loading();

        ajax('sam_security_check').then(function (res) {
            var d = res.data || res;
            var html = '';

            html += '<div class="sam-score-inline">' + d.score + '%</div>';
            html += '<div class="sam-score-label">' + d.passed + ' of ' + d.total + ' checks passed</div>';
            html += scoreBar(d.score);

            html += '<ul class="sam-checks-list">';
            var checks = d.checks || {};
            Object.keys(checks).forEach(function (key) {
                var c = checks[key];
                html += '<li>';
                html += badge(c.pass ? 'PASS' : 'FAIL', c.pass ? 'pass' : 'fail');
                html += '<span class="sam-check-label">' + escHtml(c.label) + '</span>';
                html += '<span class="sam-check-msg">' + escHtml(c.message) + '</span>';
                if (c.fixable && !c.pass && c.fix_key) {
                    html += '<button class="button button-small sam-fix-btn" data-fix="' + escHtml(c.fix_key) + '">Fix</button>';
                }
                html += '</li>';
            });
            html += '</ul>';

            html += '<div class="sam-actions">';
            html += '<button class="button" onclick="samReload(\'security\')">Re-scan</button>';
            html += '<button class="button" id="sam-core-integrity-btn">Core Integrity Check</button>';
            html += '</div>';
            html += '<div id="sam-core-integrity-result"></div>';

            pane.innerHTML = html;
        }).catch(function (err) {
            pane.innerHTML = errorBox('Failed to load security scan: ' + err.message);
        });
    }

    /* ──────────── Database ──────────── */

    function loadDatabase() {
        var pane = $('#sam-pane-database');
        pane.innerHTML = loading();

        Promise.all([
            ajax('sam_database_health'),
            ajax('sam_cleanup_stats')
        ]).then(function (results) {
            var health = results[0].data || results[0];
            var cleanup = results[1].data || results[1];
            var html = '';

            // Table sizes
            if (health.tables && health.tables.length) {
                html += '<h3 class="sam-section-title">Table Sizes</h3>';
                html += '<table class="sam-table"><thead><tr><th>Table</th><th>Rows</th><th>Size</th></tr></thead><tbody>';
                health.tables.forEach(function (t) {
                    html += '<tr><td>' + escHtml(t.name) + '</td><td>' + formatNumber(t.rows) + '</td><td>' + escHtml(t.size || formatBytes(t.data_length || 0)) + '</td></tr>';
                });
                html += '</tbody></table>';
                if (health.total_size) {
                    html += '<p><strong>Total size:</strong> ' + escHtml(health.total_size) + '</p>';
                }
            }

            // Cleanup
            html += '<h3 class="sam-section-title">Cleanup</h3>';
            html += '<form id="sam-cleanup-form">';
            html += '<ul class="sam-cleanup-items">';
            var items = cleanup.items || cleanup;
            var cleanupKeys = [
                { key: 'revisions', label: 'Post Revisions' },
                { key: 'auto_drafts', label: 'Auto Drafts' },
                { key: 'trashed_posts', label: 'Trashed Posts' },
                { key: 'spam_comments', label: 'Spam Comments' },
                { key: 'trashed_comments', label: 'Trashed Comments' },
                { key: 'expired_transients', label: 'Expired Transients' },
                { key: 'orphaned_postmeta', label: 'Orphaned Post Meta' },
                { key: 'orphaned_commentmeta', label: 'Orphaned Comment Meta' },
                { key: 'orphaned_usermeta', label: 'Orphaned User Meta' },
                { key: 'orphaned_termmeta', label: 'Orphaned Term Meta' }
            ];
            cleanupKeys.forEach(function (ck) {
                var count = (items && items[ck.key] !== undefined) ? items[ck.key] : 0;
                html += '<li><label><input type="checkbox" name="' + ck.key + '" value="1"' + (count > 0 ? '' : ' disabled') + '> ' + escHtml(ck.label) + '</label><span class="sam-cleanup-count">' + formatNumber(count) + '</span></li>';
            });
            html += '</ul>';
            html += '<button type="submit" class="button button-primary" id="sam-cleanup-btn">Clean Selected</button>';
            html += '</form>';

            html += '<div class="sam-actions"><button class="button" onclick="samReload(\'database\')">Refresh</button></div>';
            pane.innerHTML = html;
        }).catch(function (err) {
            pane.innerHTML = errorBox('Failed to load database info: ' + err.message);
        });
    }

    /* ──────────── Cron ──────────── */

    function loadCron() {
        var pane = $('#sam-pane-cron');
        pane.innerHTML = loading();

        ajax('sam_cron_list').then(function (res) {
            var d = res.data || res;
            var crons = d.crons || d || [];
            var html = '';

            html += '<table class="sam-table"><thead><tr><th>Hook</th><th>Schedule</th><th>Next Run</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
            if (Array.isArray(crons) && crons.length) {
                crons.forEach(function (c) {
                    var isDisabled = c.disabled || false;
                    html += '<tr>';
                    html += '<td><code>' + escHtml(c.hook) + '</code></td>';
                    html += '<td>' + escHtml(c.schedule || 'Once') + '</td>';
                    html += '<td>' + escHtml(c.next_run || c.next_run_human || '—') + '</td>';
                    html += '<td>' + badge(isDisabled ? 'Disabled' : 'Active', isDisabled ? 'warn' : 'pass') + '</td>';
                    html += '<td>';
                    if (!isDisabled) {
                        html += '<button class="button button-small sam-cron-run" data-hook="' + escHtml(c.hook) + '">Run Now</button> ';
                        html += '<button class="button button-small sam-cron-disable" data-hook="' + escHtml(c.hook) + '">Disable</button>';
                    } else {
                        html += '<button class="button button-small sam-cron-enable" data-hook="' + escHtml(c.hook) + '">Enable</button>';
                    }
                    html += '</td>';
                    html += '</tr>';
                });
            } else {
                html += '<tr><td colspan="5">No scheduled cron events found.</td></tr>';
            }
            html += '</tbody></table>';

            html += '<div class="sam-actions"><button class="button" onclick="samReload(\'cron\')">Refresh</button></div>';
            pane.innerHTML = html;
        }).catch(function (err) {
            pane.innerHTML = errorBox('Failed to load cron list: ' + err.message);
        });
    }

    /* ──────────── Server ──────────── */

    function loadServer() {
        var pane = $('#sam-pane-server');
        pane.innerHTML = loading();

        Promise.all([
            ajax('sam_server_resources'),
            ajax('sam_error_logs')
        ]).then(function (results) {
            var res = results[0].data || results[0];
            var logs = results[1].data || results[1];
            var html = '';

            // Resources
            html += '<h3 class="sam-section-title">Server Resources</h3>';
            html += '<div style="max-width:600px">';
            if (res.cpu !== undefined) {
                html += resourceRow('CPU Usage', res.cpu);
            }
            if (res.memory) {
                var memPct = res.memory.total > 0 ? Math.round((res.memory.used / res.memory.total) * 100) : 0;
                html += resourceRow('Memory', memPct, formatBytes(res.memory.used) + ' / ' + formatBytes(res.memory.total));
            }
            if (res.disk) {
                var diskPct = res.disk.total > 0 ? Math.round((res.disk.used / res.disk.total) * 100) : 0;
                html += resourceRow('Disk', diskPct, formatBytes(res.disk.used) + ' / ' + formatBytes(res.disk.total));
            }
            if (res.load_average) {
                html += '<div class="sam-resource-row"><label>Load Average</label><div class="sam-resource-detail">' + escHtml(Array.isArray(res.load_average) ? res.load_average.join(', ') : res.load_average) + '</div></div>';
            }
            if (res.uptime) {
                html += '<div class="sam-resource-row"><label>Uptime</label><div class="sam-resource-detail">' + escHtml(res.uptime) + '</div></div>';
            }
            html += '</div>';

            // Error logs
            html += '<h3 class="sam-section-title">Error Logs</h3>';
            var sources = logs.sources || logs.logs || [];
            if (Array.isArray(sources) && sources.length) {
                sources.forEach(function (src) {
                    var name = src.source || src.name || 'Log';
                    var entries = src.entries || src.lines || [];
                    html += '<details class="sam-log-source"' + (sources.indexOf(src) === 0 ? ' open' : '') + '>';
                    html += '<summary>' + escHtml(name) + ' (' + entries.length + ' entries)</summary>';
                    html += '<div class="sam-log-viewer">';
                    if (entries.length) {
                        entries.forEach(function (entry) {
                            var line = typeof entry === 'string' ? entry : (entry.message || entry.line || JSON.stringify(entry));
                            var lvl = typeof entry === 'object' ? (entry.level || '') : '';
                            var cls = lvl ? 'sam-log-' + lvl.toLowerCase() : '';
                            html += '<div class="sam-log-line ' + cls + '">' + escHtml(line) + '</div>';
                        });
                    } else {
                        html += '<div class="sam-log-line">No entries found.</div>';
                    }
                    html += '</div></details>';
                });
            } else if (typeof sources === 'object' && !Array.isArray(sources)) {
                // Object format: { source_name: [entries] }
                Object.keys(sources).forEach(function (name, idx) {
                    var entries = sources[name] || [];
                    html += '<details class="sam-log-source"' + (idx === 0 ? ' open' : '') + '>';
                    html += '<summary>' + escHtml(name) + ' (' + (Array.isArray(entries) ? entries.length : 0) + ' entries)</summary>';
                    html += '<div class="sam-log-viewer">';
                    if (Array.isArray(entries) && entries.length) {
                        entries.forEach(function (entry) {
                            var line = typeof entry === 'string' ? entry : (entry.message || entry.line || JSON.stringify(entry));
                            var lvl = typeof entry === 'object' ? (entry.level || '') : '';
                            var cls = lvl ? 'sam-log-' + lvl.toLowerCase() : '';
                            html += '<div class="sam-log-line ' + cls + '">' + escHtml(line) + '</div>';
                        });
                    } else {
                        html += '<div class="sam-log-line">No entries found.</div>';
                    }
                    html += '</div></details>';
                });
            } else {
                html += '<p>No error logs available.</p>';
            }

            html += '<div class="sam-actions"><button class="button" onclick="samReload(\'server\')">Refresh</button></div>';
            pane.innerHTML = html;
        }).catch(function (err) {
            pane.innerHTML = errorBox('Failed to load server info: ' + err.message);
        });
    }

    /* ──────────── Audit Log ──────────── */

    function loadAudit() {
        var pane = $('#sam-pane-audit');
        pane.innerHTML = loading();

        ajax('sam_audit_logs').then(function (res) {
            var d = res.data || res;
            var logs = d.logs || d.entries || [];
            var html = '';

            html += '<table class="sam-table"><thead><tr><th>Time</th><th>Action</th><th>Category</th><th>Details</th><th>User</th></tr></thead><tbody>';
            if (Array.isArray(logs) && logs.length) {
                logs.forEach(function (log) {
                    html += '<tr>';
                    html += '<td>' + escHtml(log.created_at || log.timestamp || '') + '</td>';
                    html += '<td>' + escHtml(log.action || '') + '</td>';
                    html += '<td>' + escHtml(log.category || '') + '</td>';
                    html += '<td>' + escHtml(log.details || log.description || '') + '</td>';
                    html += '<td>' + escHtml(log.user || log.username || '') + '</td>';
                    html += '</tr>';
                });
            } else {
                html += '<tr><td colspan="5">No audit log entries found.</td></tr>';
            }
            html += '</tbody></table>';

            html += '<div class="sam-actions"><button class="button" onclick="samReload(\'audit\')">Refresh</button></div>';
            pane.innerHTML = html;
        }).catch(function (err) {
            pane.innerHTML = errorBox('Failed to load audit logs: ' + err.message);
        });
    }

    /* ──────────── Firewall ──────────── */

    function loadFirewall() {
        var pane = $('#sam-pane-firewall');
        pane.innerHTML = loading();

        Promise.all([
            ajax('sam_ip_rules_list'),
            ajax('sam_blocked_requests')
        ]).then(function (results) {
            var rules = results[0].data || results[0];
            var blocked = results[1].data || results[1];
            var rulesList = rules.rules || rules || [];
            var blockedList = blocked.requests || blocked || [];
            var html = '';

            // Add rule form
            html += '<h3 class="sam-section-title">IP Rules</h3>';
            html += '<div class="sam-fw-form">';
            html += '<div class="sam-fw-field"><label>IP Address</label><input type="text" id="sam-fw-ip" placeholder="192.168.1.1" class="regular-text"></div>';
            html += '<div class="sam-fw-field"><label>Action</label><select id="sam-fw-action"><option value="block">Block</option><option value="allow">Allow</option></select></div>';
            html += '<div class="sam-fw-field"><label>Note</label><input type="text" id="sam-fw-note" placeholder="Optional note" class="regular-text"></div>';
            html += '<button class="button button-primary" id="sam-fw-add-btn" style="align-self:flex-end">Add Rule</button>';
            html += '</div>';

            // Rules table
            html += '<table class="sam-table" id="sam-fw-rules-table"><thead><tr><th>IP</th><th>Action</th><th>Note</th><th>Added</th><th></th></tr></thead><tbody>';
            if (Array.isArray(rulesList) && rulesList.length) {
                rulesList.forEach(function (r) {
                    html += '<tr>';
                    html += '<td><code>' + escHtml(r.ip) + '</code></td>';
                    html += '<td>' + badge(r.action, r.action === 'block' ? 'fail' : 'pass') + '</td>';
                    html += '<td>' + escHtml(r.note || '') + '</td>';
                    html += '<td>' + escHtml(r.created_at || '') + '</td>';
                    html += '<td><button class="button button-small button-link-delete sam-fw-delete" data-id="' + r.id + '">Delete</button></td>';
                    html += '</tr>';
                });
            } else {
                html += '<tr><td colspan="5">No IP rules configured.</td></tr>';
            }
            html += '</tbody></table>';

            // Blocked requests
            html += '<h3 class="sam-section-title">Blocked Requests</h3>';
            html += '<table class="sam-table"><thead><tr><th>Time</th><th>IP</th><th>URI</th><th>Method</th><th>User Agent</th></tr></thead><tbody>';
            if (Array.isArray(blockedList) && blockedList.length) {
                blockedList.slice(0, 50).forEach(function (b) {
                    html += '<tr>';
                    html += '<td>' + escHtml(b.created_at || '') + '</td>';
                    html += '<td><code>' + escHtml(b.ip || '') + '</code></td>';
                    html += '<td>' + escHtml(b.uri || '') + '</td>';
                    html += '<td>' + escHtml(b.method || '') + '</td>';
                    html += '<td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + escHtml(b.user_agent || '') + '</td>';
                    html += '</tr>';
                });
            } else {
                html += '<tr><td colspan="5">No blocked requests recorded.</td></tr>';
            }
            html += '</tbody></table>';

            html += '<div class="sam-actions"><button class="button" onclick="samReload(\'firewall\')">Refresh</button></div>';
            pane.innerHTML = html;
        }).catch(function (err) {
            pane.innerHTML = errorBox('Failed to load firewall data: ' + err.message);
        });
    }

    /* ──────────── WooCommerce ──────────── */

    function loadWooCommerce() {
        var pane = $('#sam-pane-woocommerce');
        if (!samAdmin.wooActive) {
            pane.innerHTML = '<div class="notice notice-warning inline"><p>WooCommerce is not active on this site.</p></div>';
            return;
        }
        pane.innerHTML = loading();
        fetchWooData('month');
    }

    function fetchWooData(period) {
        var pane = $('#sam-pane-woocommerce');

        Promise.all([
            ajax('sam_woo_stats', { period: period }),
            ajax('sam_woo_low_stock'),
            ajax('sam_woo_out_of_stock')
        ]).then(function (results) {
            var stats = results[0].data || results[0];
            var low = results[1].data || results[1];
            var oos = results[2].data || results[2];
            var html = '';

            // Period selector
            html += '<div class="sam-period-select"><label><strong>Period: </strong></label>';
            html += '<select id="sam-woo-period">';
            ['today', 'yesterday', 'week', 'month', 'last_month', 'year'].forEach(function (p) {
                html += '<option value="' + p + '"' + (p === period ? ' selected' : '') + '>' + p.replace('_', ' ').replace(/\b\w/g, function (l) { return l.toUpperCase(); }) + '</option>';
            });
            html += '</select></div>';

            // Stats cards
            var currency = stats.currency || '$';
            html += '<div class="sam-cards">';
            html += dashCard('Revenue', currency + formatNumber(stats.revenue || 0), '');
            html += dashCard('Orders', formatNumber(stats.orders || stats.order_count || 0), '');
            html += dashCard('Avg Order', currency + formatNumber(stats.average_order_value || 0), '');
            html += dashCard('Customers', formatNumber(stats.customers || 0), '');
            html += '</div>';

            // Low stock
            var lowProducts = low.products || low || [];
            html += '<h3 class="sam-section-title">Low Stock (' + (Array.isArray(lowProducts) ? lowProducts.length : 0) + ')</h3>';
            if (Array.isArray(lowProducts) && lowProducts.length) {
                html += '<table class="sam-table"><thead><tr><th>Product</th><th>SKU</th><th>Stock</th><th>Price</th></tr></thead><tbody>';
                lowProducts.forEach(function (p) {
                    html += '<tr><td>' + escHtml(p.name) + '</td><td>' + escHtml(p.sku || '—') + '</td><td>' + badge(p.stock, 'warn') + '</td><td>' + escHtml(p.price || '') + '</td></tr>';
                });
                html += '</tbody></table>';
            } else {
                html += '<p>No low stock products.</p>';
            }

            // Out of stock
            var oosProducts = oos.products || oos || [];
            html += '<h3 class="sam-section-title">Out of Stock (' + (Array.isArray(oosProducts) ? oosProducts.length : 0) + ')</h3>';
            if (Array.isArray(oosProducts) && oosProducts.length) {
                html += '<table class="sam-table"><thead><tr><th>Product</th><th>SKU</th><th>Price</th></tr></thead><tbody>';
                oosProducts.forEach(function (p) {
                    html += '<tr><td>' + escHtml(p.name) + '</td><td>' + escHtml(p.sku || '—') + '</td><td>' + escHtml(p.price || '') + '</td></tr>';
                });
                html += '</tbody></table>';
            } else {
                html += '<p>No out of stock products.</p>';
            }

            html += '<div class="sam-actions"><button class="button" onclick="samReload(\'woocommerce\')">Refresh</button></div>';
            pane.innerHTML = html;
        }).catch(function (err) {
            pane.innerHTML = errorBox('Failed to load WooCommerce data: ' + err.message);
        });
    }

    /* ──────────── Event Delegation ──────────── */

    function initEvents() {
        var content = document.getElementById('sam-admin-wrap');
        if (!content) return;

        content.addEventListener('click', function (e) {
            var btn = e.target.closest('button');
            if (!btn) return;

            // Security fix
            if (btn.classList.contains('sam-fix-btn')) {
                var fixKey = btn.getAttribute('data-fix');
                if (!confirm('Apply security fix: ' + fixKey + '?')) return;
                btn.disabled = true;
                btn.textContent = 'Applying...';
                ajax('sam_security_fix', { key: fixKey }).then(function (res) {
                    if (res.success) {
                        btn.textContent = 'Applied!';
                        setTimeout(function () { reloadTab('security'); }, 1000);
                    } else {
                        btn.textContent = 'Failed';
                        alert(res.message || 'Fix failed.');
                        btn.disabled = false;
                    }
                }).catch(function () {
                    btn.textContent = 'Error';
                    btn.disabled = false;
                });
            }

            // Core integrity check
            if (btn.id === 'sam-core-integrity-btn') {
                btn.disabled = true;
                btn.textContent = 'Checking...';
                var resultDiv = document.getElementById('sam-core-integrity-result');
                resultDiv.innerHTML = loading();
                ajax('sam_core_integrity').then(function (res) {
                    var d = res.data || res;
                    var html = '';
                    if (d.clean) {
                        html = '<div class="notice notice-success inline"><p>All ' + d.total_checked + ' core files match official checksums. ' + badge('Clean', 'pass') + '</p></div>';
                    } else {
                        html = '<div class="notice notice-warning inline"><p>' + (d.modified ? d.modified.length : 0) + ' modified, ' + (d.missing ? d.missing.length : 0) + ' missing out of ' + d.total_checked + ' files.</p></div>';
                        if (d.modified && d.modified.length) {
                            html += '<table class="sam-table"><thead><tr><th>Modified File</th></tr></thead><tbody>';
                            d.modified.forEach(function (f) { html += '<tr><td>' + escHtml(typeof f === 'string' ? f : f.file) + '</td></tr>'; });
                            html += '</tbody></table>';
                        }
                        if (d.missing && d.missing.length) {
                            html += '<table class="sam-table"><thead><tr><th>Missing File</th></tr></thead><tbody>';
                            d.missing.forEach(function (f) { html += '<tr><td>' + escHtml(f) + '</td></tr>'; });
                            html += '</tbody></table>';
                        }
                    }
                    resultDiv.innerHTML = html;
                    btn.disabled = false;
                    btn.textContent = 'Core Integrity Check';
                }).catch(function (err) {
                    resultDiv.innerHTML = errorBox('Integrity check failed: ' + err.message);
                    btn.disabled = false;
                    btn.textContent = 'Core Integrity Check';
                });
            }

            // Cron run
            if (btn.classList.contains('sam-cron-run')) {
                var hook = btn.getAttribute('data-hook');
                btn.disabled = true;
                btn.textContent = 'Running...';
                ajax('sam_cron_run', { hook: hook }).then(function () {
                    btn.textContent = 'Done!';
                    setTimeout(function () { reloadTab('cron'); }, 1000);
                }).catch(function () {
                    btn.textContent = 'Failed';
                    btn.disabled = false;
                });
            }

            // Cron disable
            if (btn.classList.contains('sam-cron-disable')) {
                var hook2 = btn.getAttribute('data-hook');
                if (!confirm('Disable cron: ' + hook2 + '?')) return;
                btn.disabled = true;
                ajax('sam_cron_disable', { hook: hook2 }).then(function () {
                    reloadTab('cron');
                }).catch(function () {
                    btn.disabled = false;
                    alert('Failed to disable cron.');
                });
            }

            // Cron enable
            if (btn.classList.contains('sam-cron-enable')) {
                var hook3 = btn.getAttribute('data-hook');
                var schedule = prompt('Schedule (hourly, twicedaily, daily, weekly):', 'daily');
                if (!schedule) return;
                btn.disabled = true;
                ajax('sam_cron_enable', { hook: hook3, schedule: schedule }).then(function () {
                    reloadTab('cron');
                }).catch(function () {
                    btn.disabled = false;
                    alert('Failed to enable cron.');
                });
            }

            // Firewall add rule
            if (btn.id === 'sam-fw-add-btn') {
                var ip = document.getElementById('sam-fw-ip').value.trim();
                if (!ip) { alert('Please enter an IP address.'); return; }
                var action = document.getElementById('sam-fw-action').value;
                var note = document.getElementById('sam-fw-note').value.trim();
                btn.disabled = true;
                btn.textContent = 'Adding...';
                ajax('sam_ip_rules_save', { ip: ip, fw_action: action, note: note }).then(function (res) {
                    if (res.success !== false) {
                        reloadTab('firewall');
                    } else {
                        alert(res.message || 'Failed to add rule.');
                        btn.disabled = false;
                        btn.textContent = 'Add Rule';
                    }
                }).catch(function () {
                    alert('Failed to add rule.');
                    btn.disabled = false;
                    btn.textContent = 'Add Rule';
                });
            }

            // Firewall delete rule
            if (btn.classList.contains('sam-fw-delete')) {
                var ruleId = btn.getAttribute('data-id');
                if (!confirm('Delete this IP rule?')) return;
                btn.disabled = true;
                ajax('sam_ip_rules_delete', { rule_id: ruleId }).then(function () {
                    reloadTab('firewall');
                }).catch(function () {
                    btn.disabled = false;
                    alert('Failed to delete rule.');
                });
            }
        });

        // Cleanup form submit
        content.addEventListener('submit', function (e) {
            if (e.target.id === 'sam-cleanup-form') {
                e.preventDefault();
                if (!confirm('Run database cleanup on selected items? This cannot be undone.')) return;
                var data = {};
                $$('#sam-cleanup-form input[type="checkbox"]:checked').forEach(function (cb) {
                    data[cb.name] = '1';
                });
                if (Object.keys(data).length === 0) { alert('No items selected.'); return; }
                var btn = document.getElementById('sam-cleanup-btn');
                btn.disabled = true;
                btn.textContent = 'Cleaning...';
                ajax('sam_cleanup_run', data).then(function (res) {
                    btn.textContent = 'Done!';
                    setTimeout(function () { reloadTab('database'); }, 1500);
                }).catch(function () {
                    btn.textContent = 'Failed';
                    btn.disabled = false;
                });
            }
        });

        // WooCommerce period change
        content.addEventListener('change', function (e) {
            if (e.target.id === 'sam-woo-period') {
                fetchWooData(e.target.value);
            }
        });
    }

    /* ──────────── Init ──────────── */

    // Expose reload function globally
    window.samReload = reloadTab;

    document.addEventListener('DOMContentLoaded', function () {
        if (!document.getElementById('sam-admin-wrap')) return;
        initTabs();
        initEvents();
    });

})();
