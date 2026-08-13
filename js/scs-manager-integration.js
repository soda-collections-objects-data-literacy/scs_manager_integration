/**
 * Soft protection for SCS-managed project folder roots.
 * Warns before destructive actions when the Files UI still exposes them.
 * Team Folders already prevent most root deletes; this is a second line of defence.
 */
(function () {
	'use strict'

	function getManagedNames() {
		try {
			const caps = OC.getCapabilities && OC.getCapabilities()
			const managed = caps && caps.scs_manager_integration && caps.scs_manager_integration.managedFolders
			if (!Array.isArray(managed)) {
				return []
			}
			return managed.map(function (item) {
				return item.name
			})
		} catch (e) {
			return []
		}
	}

	function isManagedName(name) {
		return getManagedNames().indexOf(name) !== -1
	}

	function warn(action, name) {
		const message = t(
			'scs_manager_integration',
			'“{name}” is managed by SCS Manager. {action} may break the project connection. Continue only if you know what you are doing.',
			{ name: name, action: action },
		)
		if (window.confirm) {
			return window.confirm(message)
		}
		OC.Notification.showTemporary(message)
		return true
	}

	// Best-effort hook for legacy file list actions if present.
	document.addEventListener('DOMContentLoaded', function () {
		const names = getManagedNames()
		if (!names.length || !window.OC) {
			return
		}

		document.body.addEventListener('click', function (event) {
			const target = event.target
			if (!(target instanceof Element)) {
				return
			}
			const row = target.closest('tr[data-file], .files-list__row, [data-cy-files-list-row-name]')
			if (!row) {
				return
			}
			const name = row.getAttribute('data-file')
				|| row.getAttribute('data-cy-files-list-row-name')
				|| ''
			if (!isManagedName(name)) {
				return
			}

			const actionEl = target.closest('[data-action], .action-delete, .action-rename')
			if (!actionEl) {
				return
			}
			const action = (actionEl.getAttribute('data-action') || actionEl.className || '').toLowerCase()
			if (action.indexOf('delete') === -1 && action.indexOf('rename') === -1 && action.indexOf('move') === -1) {
				return
			}
			const label = action.indexOf('delete') !== -1
				? t('scs_manager_integration', 'Deleting')
				: (action.indexOf('rename') !== -1
					? t('scs_manager_integration', 'Renaming')
					: t('scs_manager_integration', 'Moving'))
			if (!warn(label, name)) {
				event.preventDefault()
				event.stopPropagation()
			}
		}, true)
	})
})()
