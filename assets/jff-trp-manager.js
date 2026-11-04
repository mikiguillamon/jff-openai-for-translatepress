(() => {
  'use strict';
  const root = document.getElementById('jff-trp-manager');
  if (!root || typeof JFF_TRP_MANAGER === 'undefined') return;
  const state = document.getElementById('jff-trp-status');
  const counts = document.getElementById('jff-trp-reset-counts');
  const feedback = document.getElementById('jff-trp-feedback');
  let busy = false;

  async function request(operation, params = {}) {
    const form = new URLSearchParams();
    form.set('action', 'jff_trp_manage');
    form.set('nonce', JFF_TRP_MANAGER.nonce);
    form.set('operation', operation);
    for (const [key, value] of Object.entries(params)) form.set(key, String(value));
    const response = await fetch(JFF_TRP_MANAGER.ajax, {
      method: 'POST', body: form, credentials: 'same-origin', cache: 'no-store'
    });
    const data = await response.json();
    if (!data || !data.success) throw new Error(data && data.data && data.data.message || 'Request failed.');
    return data.data;
  }
  function report(message, error = false) {
    feedback.textContent = message;
    feedback.style.color = error ? '#b32d2e' : '#135e29';
  }
  function show(data) {
    const job = data.job;
    if (!job) {
      state.textContent = 'No translation job has been started.';
    } else {
      const lines = [
        'Status: ' + job.status,
        'Phase: ' + job.phase,
        'Languages: ' + (job.languages || []).join(', '),
        'Public URLs visited: ' + (job.urls_done || 0) + ' / ' + (job.url_total || 0),
        'Scoped dictionary strings saved: ' + (job.strings_done || 0),
        'Changed from original: ' + Math.max(0, (job.strings_done || 0) - (job.strings_unchanged || 0)),
        'Saved unchanged: ' + (job.strings_unchanged || 0),
        'Skipped: ' + (job.strings_skipped || 0),
        'Errors: ' + (job.failed || 0),
        'Needs review: ' + (job.review_items || []).length
      ];
      if (job.next_attempt && job.status === 'waiting')
        lines.push('Next attempt: ' + new Date(job.next_attempt * 1000).toLocaleString());
      if (job.scope_mode !== 'rendered_urls_v1') {
        lines.push('Previous job scope is obsolete: stop and start a fresh job after upgrading. Saved translations remain intact.');
      }
      if (job.last_error) lines.push('Last message: ' + job.last_error);
      for (const item of (job.review_items || []).slice(-5)) {
        lines.push('Review: ' + item.language + ' dictionary ID ' + item.id + ', engine ' + item.engine_status + '/' + item.reason + ', HTTP ' + item.http);
      }
      if (job.updated) lines.push('Updated: ' + new Date(job.updated * 1000).toLocaleString());
      state.textContent = lines.join('\n');
    }
    const table = document.createElement('table');
    table.className = 'widefat striped';
    table.style.maxWidth = '760px';
    const head = document.createElement('thead');
    head.innerHTML = '<tr><th>Language</th><th>Dictionary translations</th><th>Gettext</th><th>Translated URL slugs</th></tr>';
    table.appendChild(head);
    const body = document.createElement('tbody');
    for (const [language, values] of Object.entries(data.counts || {})) {
      const row = document.createElement('tr');
      for (const value of [language, values.error || values.dictionary, values.error ? '-' : values.gettext, values.error ? '-' : values.slugs]) {
        const cell = document.createElement('td');
        cell.textContent = String(value == null ? '-' : value);
        row.appendChild(cell);
      }
      body.appendChild(row);
    }
    table.appendChild(body);
    counts.replaceChildren(table);
  }
  async function refresh() {
    if (busy) return;
    try { show(await request('status')); }
    catch (error) { state.textContent = 'Could not read job status: ' + error.message; }
  }
  root.addEventListener('click', async (event) => {
    const button = event.target.closest('button[data-jff-action]');
    if (!button || busy) return;
    const operation = button.dataset.jffAction;
    const language = button.dataset.lang;
    const params = {};
    if (operation === 'start') {
      params.language = language;
      params.extra_urls = document.getElementById('jff-extra-urls').value;
      if (!window.confirm('Start translation for ' + (language === 'all' ? 'all languages' : language) + '? This may incur OpenAI API charges.')) return;
    } else if (operation === 'reset') {
      if (!document.getElementById('jff-confirm-backup').checked) {
        report('Verify a restorable full database backup before resetting.', true);
        return;
      }
      const includeSlugs = document.getElementById('jff-reset-slugs').checked;
      const required = language === 'all' ? 'DELETE ALL' : 'DELETE ' + language;
      const typed = window.prompt('This permanently resets manual and automatic translations for ' + language + '. ' +
        (includeSlugs ? 'Translated URL slugs will also be deleted; old links may break.' : 'Translated URL slugs will be kept.') +
        '\nType exactly: ' + required);
      if (typed !== required) {
        report('Reset cancelled: confirmation did not match.', true);
        return;
      }
      params.language = language;
      params.backup_checked = '1';
      params.include_slugs = includeSlugs ? '1' : '0';
      params.confirmation = typed;
    }
    busy = true;
    button.disabled = true;
    try {
      const result = await request(operation, params);
      if (operation === 'reset') {
        report('Translations reset: ' + JSON.stringify(result.deleted || {}));
        show(result.state);
      } else {
        report('Action completed: ' + operation);
        show(result);
      }
    } catch (error) {
      report(error.message, true);
    } finally {
      busy = false;
      button.disabled = false;
    }
  });
  refresh();
  setInterval(refresh, 5000);
})();