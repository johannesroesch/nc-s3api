<!--
  SPDX-FileCopyrightText: 2026 Johannes Roesch <johannes.roesch@googlemail.com>
  SPDX-License-Identifier: GPL-2.0-or-later
-->
<div id="nc-s3api-user" class="section">
    <h2><?php p($l->t('S3 API Gateway')); ?></h2>
    <p class="settings-hint">
        <?php p($l->t('Create a dedicated S3 Access Key / Secret Key pair for your account (Mode A). The secret is shown only once — save it immediately.')); ?>
    </p>
    <p class="settings-hint">
        <strong><?php p($l->t('Endpoint URL:')); ?></strong>
        <code><?php p($_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . \OC::$WEBROOT . '/index.php/apps/nc_s3api/s3/'); ?></code>
    </p>

    <table id="nc-s3api-user-credentials-table" class="grid">
        <thead>
            <tr>
                <th><?php p($l->t('Label')); ?></th>
                <th><?php p($l->t('Access Key')); ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>

    <h3><?php p($l->t('Add key-pair')); ?></h3>
    <form id="nc-s3api-user-add-form">
        <p>
            <label for="nc-s3api-user-secret"><?php p($l->t('Secret Key (you choose, min 16 chars)')); ?></label>
            <input type="password" id="nc-s3api-user-secret" minlength="16" required />
        </p>
        <p>
            <label for="nc-s3api-user-label"><?php p($l->t('Label (optional)')); ?></label>
            <input type="text" id="nc-s3api-user-label" placeholder="My local backup" />
        </p>
        <button type="submit" class="button"><?php p($l->t('Add')); ?></button>
    </form>

    <div id="nc-s3api-new-key-hint" style="display:none; background:#ffffcc; padding:1em; margin-top:1em;">
        <strong><?php p($l->t('New key-pair created — save the Access Key and Secret Key now, the secret will not be shown again.')); ?></strong><br>
        <?php p($l->t('Access Key:')); ?> <code id="nc-s3api-new-access"></code><br>
        <?php p($l->t('Secret Key:')); ?> <code id="nc-s3api-new-secret"></code>
    </div>
</div>

<script nonce="<?php p($_SERVER['HTTP_X_CSP_NONCE'] ?? ''); ?>">
(function () {
    const baseUrl = OC.generateUrl('/apps/nc_s3api/user/credentials');

    async function load() {
        const res  = await fetch(baseUrl, { headers: { 'OCS-APIREQUEST': 'true' } });
        const rows = await res.json();
        const tbody = document.querySelector('#nc-s3api-user-credentials-table tbody');
        tbody.innerHTML = '';
        rows.forEach(row => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${escHtml(row.label)}</td>
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

    document.getElementById('nc-s3api-user-add-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const body = new URLSearchParams({
            secretKey: document.getElementById('nc-s3api-user-secret').value,
            label:     document.getElementById('nc-s3api-user-label').value,
        });
        const res  = await fetch(baseUrl, { method: 'POST', body, headers: { 'requesttoken': OC.requestToken } });
        const data = await res.json();
        e.target.reset();
        document.getElementById('nc-s3api-new-access').textContent = data.accessKey;
        document.getElementById('nc-s3api-new-secret').textContent = data.secretKey;
        document.getElementById('nc-s3api-new-key-hint').style.display = 'block';
        load();
    });

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    load();
})();
</script>
