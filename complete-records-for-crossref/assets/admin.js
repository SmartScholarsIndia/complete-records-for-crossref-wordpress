/* Complete Records for Crossref — the authors table (add / remove rows) and the confirmation before a deposit. */
(function () {
    'use strict';
    function renumber(tbody) {
        Array.prototype.forEach.call(tbody.querySelectorAll('tr'), function (tr, i) {
            Array.prototype.forEach.call(tr.querySelectorAll('input'), function (inp) {
                inp.name = inp.name.replace(/ccrwp\[authors\]\[\d+\]/, 'ccrwp[authors][' + i + ']');
            });
        });
    }
    document.addEventListener('click', function (e) {
        var add = e.target.closest('.ccrwp-add');
        if (add) {
            var tbody = add.closest('.ccrwp-box').querySelector('.ccrwp-authors tbody');
            var last = tbody.querySelector('tr:last-child');
            var row = last.cloneNode(true);
            Array.prototype.forEach.call(row.querySelectorAll('input'), function (inp) { inp.value = ''; });
            tbody.appendChild(row);
            renumber(tbody);
            row.querySelector('input').focus();
            return;
        }
        var rm = e.target.closest('.ccrwp-remove');
        if (rm) {
            var body = rm.closest('tbody');
            if (body.querySelectorAll('tr').length > 1) rm.closest('tr').remove();
            else Array.prototype.forEach.call(body.querySelectorAll('input'), function (inp) { inp.value = ''; });
            renumber(body);
        }
    });
    document.addEventListener('submit', function (e) {
        var msg = e.target.getAttribute && e.target.getAttribute('data-confirm');
        if (msg && !window.confirm(msg)) e.preventDefault();
    });
})();
