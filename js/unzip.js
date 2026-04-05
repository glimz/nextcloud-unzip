(function () {
	const MIME_TYPE_MAP = {
		'application/zip': 'zip',
		'application/x-zip-compressed': 'zip',
		'application/x-rar-compressed': 'rar',
		'application/vnd.rar': 'rar',
		'application/x-rar': 'rar',
		'application/x-tar': 'other',
		'application/x-7z-compressed': 'other',
		'application/x-bzip2': 'other',
		'application/x-deb': 'other',
		'application/x-gzip': 'other',
		'application/x-compressed': 'other',
	};

	const EXTENSION_MAP = {
		'.zip': 'zip',
		'.rar': 'rar',
		'.7z': 'other',
		'.tar': 'other',
		'.tgz': 'other',
		'.tar.gz': 'other',
		'.gz': 'other',
		'.bz2': 'other',
		'.deb': 'other',
	};

	const APP_ID = 'unzip';
	const ACTION_ID = 'unzip-extract';
	let registered = false;

	const isDebugEnabled = () => {
		try {
			return !!window.localStorage && window.localStorage.getItem('unzipDebug') === '1';
		} catch (_e) {
			return false;
		}
	};

	const debugPing = (stage, detail) => {
		if (!isDebugEnabled()) {
			return;
		}
		try {
			const url = OC.generateUrl('/apps/unzip/ping') + '?stage=' + encodeURIComponent(stage) + '&detail=' + encodeURIComponent(detail || '');
			(new Image()).src = url + '&_=' + Date.now();
		} catch (_e) {
			// ignore
		}
	};

	const getType = (node) => {
		const mime = node.mime || node.mimetype || node.mimeType || '';
		if (MIME_TYPE_MAP[mime]) {
			return MIME_TYPE_MAP[mime];
		}
		const name = (node.basename || node.name || node.filename || node.path || '').toLowerCase();
		for (const [ext, type] of Object.entries(EXTENSION_MAP)) {
			if (name.endsWith(ext)) {
				return type;
			}
		}
		return null;
	};

	const notify = (message) => {
		if (window.OC && window.OC.Notification && typeof window.OC.Notification.showTemporary === 'function') {
			window.OC.Notification.showTemporary(message);
		}
	};

	const refreshFilesView = () => {
		try {
			if (window.OCA && window.OCA.Files && window.OCA.Files.App && window.OCA.Files.App.fileList && typeof window.OCA.Files.App.fileList.reload === 'function') {
				window.OCA.Files.App.fileList.reload();
			}
		} catch (_e) {
			// ignore
		}
	};

	const extractNode = (node) => {
		const type = getType(node);
		const fileId = node.fileid || node.fileId || node.id;
		if (!type || !fileId) {
			return Promise.resolve(false);
		}

		return new Promise((resolve) => {
			$.ajax({
				type: 'POST',
				url: OC.generateUrl('/apps/' + APP_ID + '/extract'),
				headers: { requesttoken: OC.requestToken },
				data: { fileId, type },
				success: function (response) {
					if (response && response.code === 1) {
						const files = (typeof response.files === 'number') ? response.files : null;
						const folders = (typeof response.folders === 'number') ? response.folders : null;
						const parts = [];
						if (folders !== null) parts.push(folders + ' folders');
						if (files !== null) parts.push(files + ' files');
						notify(parts.length ? ('Extracted here (' + parts.join(', ') + ')') : 'Extracted here');
						refreshFilesView();
						resolve(true);
						return;
					}
					notify((response && response.desc) ? response.desc : 'Extraction failed');
					resolve(false);
				},
				error: function (xhr) {
					try {
						const desc = (xhr && xhr.responseJSON && xhr.responseJSON.desc) ? xhr.responseJSON.desc : null;
						if (desc) {
							notify(desc);
						} else if (xhr && typeof xhr.responseText === 'string') {
							const parsed = JSON.parse(xhr.responseText);
							notify((parsed && parsed.desc) ? parsed.desc : 'Extraction request failed');
						} else {
							notify('Extraction request failed');
						}
					} catch (_e) {
						notify('Extraction request failed');
					}
					resolve(false);
				},
			});
		});
	};

	const enabled = (nodes, view) => {
		const resolvedNodes = Array.isArray(nodes) ? nodes : (nodes && Array.isArray(nodes.nodes) ? nodes.nodes : []);
		const resolvedView = (nodes && nodes.view) ? nodes.view : view;
		if (resolvedView && resolvedView.id === 'trashbin') {
			return false;
		}
		if (!resolvedNodes || resolvedNodes.length !== 1) {
			return false;
		}
		return !!getType(resolvedNodes[0]);
	};

	const execBatch = async (_source, _currentPath, selectedNodes) => {
		// Nextcloud Files v4 signature: (context)
		// Alternative v4 signature: (nodes, view, currentDirectory)
		// Legacy signature: (source, currentPath, selectedNodes)
		const nodes = (_source && Array.isArray(_source.nodes))
			? _source.nodes
			: (Array.isArray(_source) ? _source : (Array.isArray(selectedNodes) ? selectedNodes : null));
		if (!nodes || nodes.length === 0) {
			return [];
		}
		const result = extractNode(nodes[0]);
		return Promise.all(nodes.map(function () { return result; }));
	};

	const exec = async (source, _currentPath, selectedNodes) => {
		// Nextcloud Files v4 signature: (context)
		// Alternative v4 signature: (node, view, currentDirectory)
		// Legacy signature: (source, currentPath, selectedNodes)
		if (source && Array.isArray(source.nodes) && source.nodes[0]) {
			return extractNode(source.nodes[0]);
		}
		if (Array.isArray(selectedNodes) && selectedNodes[0]) {
			return extractNode(selectedNodes[0]);
		}
		return extractNode(source);
	};

	const buildAction = () => ({
		id: ACTION_ID,
		order: 64,
		displayName: function () { return 'Extract here'; },
		iconSvgInline: function () {
			return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M4 3h16a1 1 0 0 1 1 1v4h-2V5H5v14h6v2H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Zm8 6 5 5h-3v7h-4v-7H7l5-5Zm9 9v-7h-2v7h2Z"/></svg>';
		},
		enabled,
		execBatch,
		exec,
	});

	const registerModern = () => {
		const scope = window._nc_files_scope && window._nc_files_scope.v4_0 ? window._nc_files_scope.v4_0 : null;
		if (!scope) {
			debugPing('modern_missing', 'no_scope');
			return false;
		}
		try {
			const actionData = buildAction();
			const action = typeof scope.FileAction === 'function' ? new scope.FileAction(actionData) : actionData;

			// Nextcloud Files v4 keeps the action registry on the shared scope object.
			// (Apps built with @nextcloud/files call an internal registerFileAction() that updates these.)
			scope.fileActions = scope.fileActions || new Map();
			if (!scope.fileActions.has(action.id)) {
				scope.fileActions.set(action.id, action);
			}

			if (!scope.registry || (typeof scope.registry.dispatchEvent !== 'function' && typeof scope.registry.dispatchTypedEvent !== 'function')) {
				scope.registry = new (class extends EventTarget {
					dispatchTypedEvent(_name, event) {
						return super.dispatchEvent(event);
					}
				})();
			}
			const event = new CustomEvent('register:action', { detail: action });
			if (typeof scope.registry.dispatchTypedEvent === 'function') {
				scope.registry.dispatchTypedEvent('register:action', event);
			} else {
				scope.registry.dispatchEvent(event);
			}

			registered = true;
			if (isDebugEnabled() && console && typeof console.debug === 'function') {
				console.debug('[unzip] registered modern action', action.id);
			}
			debugPing('modern_registered', 'ok');
			return true;
		} catch (e) {
			if (isDebugEnabled() && console && typeof console.debug === 'function') {
				console.debug('[unzip] modern register error', e);
			}
			debugPing('modern_error', (e && e.message) ? e.message : 'unknown');
			return false;
		}
	};

	const registerStoreFallback = () => {
		if (!Array.isArray(window._nc_fileactions)) {
			debugPing('store_missing', 'no_array');
			return false;
		}
		if (window._nc_fileactions.find((a) => a && a.id === ACTION_ID)) {
			registered = true;
			debugPing('store_exists', 'already');
			return true;
		}

		try {
			const scope = window._nc_files_scope && window._nc_files_scope.v4_0 ? window._nc_files_scope.v4_0 : null;
			const actionData = buildAction();
			const action = (scope && typeof scope.FileAction === 'function') ? new scope.FileAction(actionData) : actionData;
			window._nc_fileactions.push(action);
			registered = true;
			debugPing('store_registered', 'ok');
			return true;
		} catch (e) {
			debugPing('store_error', (e && e.message) ? e.message : 'unknown');
			return false;
		}
	};

	const registerLegacy = () => {
		if (!window.OCA || !window.OCA.Files || !window.OCA.Files.fileActions) {
			debugPing('legacy_missing', 'no_api');
			return false;
		}
		Object.keys(MIME_TYPE_MAP).forEach((mime) => {
			try {
				window.OCA.Files.fileActions.registerAction({
					name: ACTION_ID + '-' + mime.replace(/[^a-z0-9]/gi, '-'),
					displayName: 'Extract here',
					mime,
					permissions: window.OC.PERMISSION_READ,
					type: window.OCA.Files.FileActions.TYPE_DROPDOWN,
					iconClass: 'icon-folder',
					actionHandler: function (filename, context) {
						const attrs = context.fileInfoModel && context.fileInfoModel.attributes ? context.fileInfoModel.attributes : {};
						extractNode({
							mime: attrs.mimetype || attrs.mime,
							basename: attrs.name || attrs.basename || filename,
							fileid: attrs.fileid || attrs.id,
						});
					},
				});
			} catch (_e) {}
		});
		registered = true;
		debugPing('legacy_registered', 'ok');
		return true;
	};

	const tryRegister = () => {
		if (registered) {
			return;
		}
		if (registerModern()) {
			return;
		}
		if (registerStoreFallback()) {
			return;
		}
		registerLegacy();
	};

	debugPing('loaded', 'start');
	if (isDebugEnabled() && console && typeof console.debug === 'function') {
		console.debug('[unzip] loaded', { hasFilesScope: !!(window._nc_files_scope && window._nc_files_scope.v4_0) });
	}
	tryRegister();
	let attempts = 0;
	const timer = setInterval(function () {
		attempts += 1;
		tryRegister();
		if (registered || attempts > 40) {
			debugPing('done', registered ? 'registered' : 'not_registered');
			clearInterval(timer);
		}
	}, 500);
})();
