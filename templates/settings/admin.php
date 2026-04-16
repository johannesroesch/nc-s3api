<!--
  SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
  SPDX-License-Identifier: GPL-2.0-or-later
-->
<div id="nc-s3api-admin" class="section">
    <h2><?php p($l->t('S3 API Gateway — Admin Credentials')); ?></h2>
    <p class="settings-hint">
        <?php p($l->t('Create key-pairs that map an Access Key / Secret Key to a Nextcloud user (Mode B).')); ?>
    </p>

    <table id="nc-s3api-credentials-table" class="grid">
        <thead>
            <tr>
                <th><?php p($l->t('Label')); ?></th>
                <th><?php p($l->t('Nextcloud User')); ?></th>
                <th><?php p($l->t('Access Key')); ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>

    <h3><?php p($l->t('Add key-pair')); ?></h3>
    <form id="nc-s3api-admin-add-form">
        <p>
            <label for="nc-s3api-admin-user"><?php p($l->t('Nextcloud Username')); ?></label>
            <input type="text" id="nc-s3api-admin-user" placeholder="alice" required />
        </p>
        <p>
            <label for="nc-s3api-admin-access"><?php p($l->t('Access Key (leave blank to auto-generate)')); ?></label>
            <input type="text" id="nc-s3api-admin-access" placeholder="AKIAIOSFODNN7EXAMPLE" />
        </p>
        <p>
            <label for="nc-s3api-admin-secret"><?php p($l->t('Secret Key')); ?></label>
            <input type="password" id="nc-s3api-admin-secret" required />
        </p>
        <p>
            <label for="nc-s3api-admin-label"><?php p($l->t('Label (optional)')); ?></label>
            <input type="text" id="nc-s3api-admin-label" placeholder="CI/CD pipeline" />
        </p>
        <button type="submit" class="button"><?php p($l->t('Add')); ?></button>
    </form>
</div>

<script nonce="<?php p($_SERVER['HTTP_X_CSP_NONCE'] ?? ''); ?>">
(function () {
    const baseUrl = OC.generateUrl('/apps/nc_s3api/admin/credentials');

    async function load() {
        const res  = await fetch(baseUrl, { headers: { 'OCS-APIREQUEST': 'true' } });
        const rows = await res.json();
        const tbody = document.querySelector('#nc-s3api-credentials-table tbody');
        tbody.innerHTML = '';
        rows.forEach(row => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${escHtml(row.label)}</td>
                <td>${escHtml(row.userId)}</td>
                <td><code>${escHtml(row.accessKey)}</code></td>
                <td><button class="button icon-delete" data-id="${row.id}"><?php p($l->t('Delete')); ?></button></td>
            `;
            tbody.appendChild(tr);
        });
        tbody.querySelectorAll('[data-id]').forEach(btn => {
            btn.addEventListener('click', () => remove(btn.dataset.id));
        });
    }

    async function remove(id) {
        await fetch(baseUrl + '/' + id, { method: 'DELETE', headers: { 'requesttoken': OC.requestToken } });
        load();
    }

    document.getElementById('nc-s3api-admin-add-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const body = new URLSearchParams({
            userId:    document.getElementById('nc-s3api-admin-user').value,
            accessKey: document.getElementById('nc-s3api-admin-access').value,
            secretKey: document.getElementById('nc-s3api-admin-secret').value,
            label:     document.getElementById('nc-s3api-admin-label').value,
        });
        await fetch(baseUrl, { method: 'POST', body, headers: { 'requesttoken': OC.requestToken } });
        e.target.reset();
        load();
    });

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    load();
})();
</script>
