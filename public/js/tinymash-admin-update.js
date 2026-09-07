(function () {
    'use strict';

    const root = document.getElementById('tm-update-installer');
    if (!(root instanceof HTMLElement)) {
        return;
    }

    const form = root.querySelector('[data-tm-update-form]');
    const confirmRegion = root.querySelector('[data-tm-update-confirm]');
    const confirmError = root.querySelector('[data-tm-update-confirm-error]');
    const progressRegion = root.querySelector('[data-tm-update-progress]');
    const progressHeading = root.querySelector('[data-tm-update-progress-heading]');
    const summary = root.querySelector('[data-tm-update-summary]');
    const errorMessage = root.querySelector('[data-tm-update-error]');
    const actions = root.querySelector('[data-tm-update-actions]');
    const resumeButton = root.querySelector('[data-tm-update-resume]');
    const rollbackButton = root.querySelector('[data-tm-update-rollback]');
    const reloadLink = root.querySelector('[data-tm-update-reload]');
    const progressbar = root.querySelector('[data-tm-update-progressbar]');
    const progressbarValue = root.querySelector('[data-tm-update-progressbar-value]');
    const handoffUrl = root.getAttribute('data-handoff-url') || '';
    const healthUrl = root.getAttribute('data-health-url') || '';
    const csrfToken = document.querySelector('meta[name="tinymash-csrf-token"]')?.getAttribute('content') || '';
    const expiredRecoveryMessage = 'Reload Updates if the site is available. If maintenance remains active, run php8.4 bin/tinymash.php update:recover status from the installation directory.';
    let endpoint = '';
    let token = '';
    let maintenanceActive = false;
    let polling = false;
    let lastStatus = null;

    function setHidden(element, hidden) {
        if (element instanceof HTMLElement) {
            element.hidden = hidden;
        }
    }

    function sameOriginUrl(value, label) {
        let url = null;
        try {
            url = new URL(value, window.location.href);
        } catch (error) {
            url = null;
        }
        if (!(url instanceof URL) || url.origin !== window.location.origin) {
            throw new Error(label + ' is not on this site.');
        }
        return url.href;
    }

    function setSummary(message) {
        if (summary instanceof HTMLElement) {
            summary.textContent = message;
        }
    }

    function showError(message, expired) {
        if (expired) {
            maintenanceActive = statusHasMaintenance(lastStatus);
        }
        setSummary(expired ? 'Recovery access expired.' : 'The update needs attention.');
        if (errorMessage instanceof HTMLElement) {
            errorMessage.textContent = message;
            errorMessage.hidden = false;
        }
        setHidden(actions, false);
        setHidden(reloadLink, maintenanceActive && !expired);
    }

    function clearError() {
        if (errorMessage instanceof HTMLElement) {
            errorMessage.textContent = '';
            errorMessage.hidden = true;
        }
        setHidden(actions, true);
        setHidden(resumeButton, true);
        setHidden(rollbackButton, true);
        setHidden(reloadLink, true);
    }

    function statusLabel(step) {
        const status = String(step?.status || 'waiting');
        if (status === 'complete') {
            return 'Complete';
        }
        if (status === 'running') {
            return 'In progress';
        }
        return 'Waiting';
    }

    function statusHasMaintenance(status) {
        if (typeof status?.maintenance_active === 'boolean') {
            return status.maintenance_active;
        }
        const phase = String(status?.phase || '');
        const managedStatus = String(status?.steps?.managed_files?.status || 'waiting');
        return ['installing', 'files_replaced', 'health_passed', 'rolling_back'].includes(phase)
            || (phase === 'failed' && managedStatus !== 'waiting');
    }

    function renderStatus(status) {
        if (!status || typeof status !== 'object') {
            return;
        }
        lastStatus = status;
        maintenanceActive = statusHasMaintenance(status);
        const steps = status.steps && typeof status.steps === 'object' ? status.steps : {};
        Object.entries(steps).forEach(([name, step]) => {
            const row = root.querySelector('[data-tm-update-step="' + name + '"]');
            if (!(row instanceof HTMLElement)) {
                return;
            }
            let label = statusLabel(step);
            if (name === 'managed_files' && Number(step?.total) > 0) {
                const completed = Math.min(Number(step.total), Math.max(0, Number(step.completed) || 0));
                label += ' (' + completed + ' of ' + Number(step.total) + ')';
                const percentage = Math.round((completed / Number(step.total)) * 100);
                if (progressbar instanceof HTMLElement && progressbarValue instanceof HTMLElement) {
                    progressbar.hidden = false;
                    progressbar.setAttribute('aria-valuenow', String(percentage));
                    progressbarValue.style.width = percentage + '%';
                }
            }
            row.textContent = label;
        });

        const phase = String(status.phase || '');
        if (phase === 'prepared') {
            setSummary('Preparation complete. Starting the update.');
        } else if (phase === 'installing') {
            setSummary('Replacing application files. Do not close this page.');
        } else if (phase === 'files_replaced') {
            setSummary('Application files replaced. Checking the updated site.');
        } else if (phase === 'health_passed' || (phase === 'complete' && maintenanceActive)) {
            setSummary('Site checks passed. Resume the update to reopen the site.');
            setHidden(actions, false);
            setHidden(resumeButton, false);
            setHidden(rollbackButton, true);
            setHidden(reloadLink, true);
        } else if (phase === 'rolled_back' && maintenanceActive) {
            setSummary('Application files restored. Finish rollback to reopen the site.');
            setHidden(actions, false);
            setHidden(resumeButton, true);
            setHidden(rollbackButton, false);
            setHidden(reloadLink, true);
        } else if (phase === 'complete') {
            clearError();
            maintenanceActive = false;
            setSummary('Update complete. tinymash ' + String(status.target_version || '') + ' is running.');
            setHidden(actions, false);
            setHidden(reloadLink, false);
        } else if (phase === 'rolled_back') {
            clearError();
            maintenanceActive = false;
            setSummary('Rollback complete. tinymash ' + String(status.installed_version || '') + ' is running.');
            const healthStep = root.querySelector('[data-tm-update-step="health_check"]');
            if (healthStep instanceof HTMLElement) {
                healthStep.textContent = 'Not needed';
            }
            setHidden(actions, false);
            setHidden(reloadLink, false);
        } else if (phase === 'failed') {
            setSummary('The update stopped before completion.');
            if (errorMessage instanceof HTMLElement) {
                errorMessage.textContent = String(status.error || 'The recovery action could not be completed.');
                errorMessage.hidden = false;
            }
            const managed = steps.managed_files && typeof steps.managed_files === 'object' ? steps.managed_files : {};
            const recoverable = String(managed.status || 'waiting') !== 'waiting';
            maintenanceActive = recoverable;
            setHidden(actions, false);
            setHidden(resumeButton, !recoverable);
            setHidden(rollbackButton, !recoverable);
            setHidden(reloadLink, recoverable);
        }
    }

    async function readJsonResponse(response) {
        const text = await response.text();
        let payload = null;
        try {
            payload = JSON.parse(text);
        } catch (error) {
            payload = null;
        }
        if (!payload || typeof payload !== 'object') {
            const failure = new Error('The server returned an unexpected response.');
            failure.status = response.status;
            throw failure;
        }
        if (!response.ok) {
            const failure = new Error(String(payload.error || 'The recovery action could not be completed.'));
            failure.status = response.status;
            throw failure;
        }
        return payload;
    }

    async function requestHandoff(password) {
        const response = await fetch(sameOriginUrl(handoffUrl, 'The update handoff'), {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-tinymash-CSRF': csrfToken
            },
            body: JSON.stringify({ current_password: password })
        });
        return readJsonResponse(response);
    }

    async function requestRecovery(action) {
        const body = new URLSearchParams({ action, token });
        const response = await fetch(sameOriginUrl(endpoint, 'The update recovery endpoint'), {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Accept': 'application/json' },
            body
        });
        return readJsonResponse(response);
    }

    async function requestHealth() {
        const response = await fetch(sameOriginUrl(healthUrl, 'The update health endpoint'), {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Accept': 'application/json' },
            body: new URLSearchParams({ token })
        });
        return readJsonResponse(response);
    }

    async function pollStatus() {
        if (polling || endpoint === '' || token === '') {
            return;
        }
        polling = true;
        try {
            const payload = await requestRecovery('status');
            renderStatus(payload.status);
        } catch (error) {
            if (Number(error.status) === 404) {
                showError(expiredRecoveryMessage, true);
            }
        } finally {
            polling = false;
        }
    }

    async function runRecoveryAction(action) {
        maintenanceActive = true;
        clearError();
        setHidden(actions, true);
        const pollTimer = window.setInterval(pollStatus, 600);
        try {
            const finishHealth = action === 'resume' && ['health_passed', 'complete'].includes(String(lastStatus?.phase || ''));
            const payload = finishHealth ? { status: lastStatus } : await requestRecovery(action);
            renderStatus(payload.status);
            if (finishHealth || String(payload.status?.phase || '') === 'files_replaced') {
                setSummary('Application files replaced. Checking the updated site.');
                try {
                    await requestHealth();
                    const finalPayload = await requestRecovery('status');
                    renderStatus(finalPayload.status);
                } catch (error) {
                    await pollStatus();
                    const phase = String(lastStatus?.phase || '');
                    if (phase === 'complete' && !statusHasMaintenance(lastStatus)) {
                        renderStatus(lastStatus);
                    } else if (Number(error.status) === 404) {
                        showError('Recovery access expired before the site check completed. ' + expiredRecoveryMessage, true);
                    } else if (['health_passed', 'complete'].includes(phase)) {
                        showError('Maintenance could not be cleared. Resume the update to reopen the site.', false);
                        setHidden(resumeButton, false);
                        setHidden(rollbackButton, true);
                    } else if (phase !== 'failed') {
                        showError('The updated site did not pass its health check. Resume the update or roll back.', false);
                        setHidden(resumeButton, false);
                        setHidden(rollbackButton, false);
                    }
                }
            }
        } catch (error) {
            await pollStatus();
            const phase = String(lastStatus?.phase || '');
            if (['complete', 'rolled_back'].includes(phase) && !statusHasMaintenance(lastStatus)) {
                renderStatus(lastStatus);
            } else if (Number(error.status) === 404) {
                showError('Recovery access expired. ' + expiredRecoveryMessage, true);
            } else if (phase !== 'failed') {
                showError(String(error.message || 'The recovery action could not be completed.'), false);
            }
        } finally {
            window.clearInterval(pollTimer);
        }
    }

    if (form instanceof HTMLFormElement) {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const passwordField = form.elements.namedItem('current_password');
            if (!(passwordField instanceof HTMLInputElement) || passwordField.value === '') {
                form.reportValidity();
                return;
            }
            const submitButton = form.querySelector('button[type="submit"]');
            if (submitButton instanceof HTMLButtonElement) {
                submitButton.disabled = true;
            }
            setHidden(confirmRegion, true);
            setHidden(progressRegion, false);
            if (progressHeading instanceof HTMLElement) {
                progressHeading.focus({ preventScroll: true });
            }
            if (confirmError instanceof HTMLElement) {
                confirmError.textContent = '';
                confirmError.hidden = true;
            }
            setSummary('Preparing the update and verified recovery files.');
            try {
                const handoff = await requestHandoff(passwordField.value);
                passwordField.value = '';
                endpoint = sameOriginUrl(String(handoff.endpoint || ''), 'The update recovery endpoint');
                token = String(handoff.token || '');
                if (endpoint === '' || token === '') {
                    throw new Error('The update handoff was incomplete.');
                }
                renderStatus(handoff.status);
                await runRecoveryAction('start');
            } catch (error) {
                passwordField.value = '';
                maintenanceActive = false;
                if (confirmError instanceof HTMLElement) {
                    confirmError.textContent = String(error.message || 'The update could not be prepared.');
                    confirmError.hidden = false;
                }
                setHidden(confirmRegion, false);
                setHidden(progressRegion, true);
                if (submitButton instanceof HTMLButtonElement) {
                    submitButton.disabled = false;
                }
                passwordField.focus();
            }
        });
    }

    if (resumeButton instanceof HTMLButtonElement) {
        resumeButton.addEventListener('click', () => runRecoveryAction('resume'));
    }
    if (rollbackButton instanceof HTMLButtonElement) {
        rollbackButton.addEventListener('click', () => runRecoveryAction('rollback'));
    }
    window.addEventListener('beforeunload', (event) => {
        if (!maintenanceActive) {
            return;
        }
        event.preventDefault();
        event.returnValue = '';
    });
}());
