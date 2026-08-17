var Store = (function () {
    var _version = 0;
    var state = {
        files: {
            parentId: 0,
            view: 'list',
            sort: 'name',
            sortOrder: 'asc',
            selectedIds: new Set(),
            contextFileId: null,
            contextFileData: null,
            isFirstLoad: true,
            list: [],
            abortController: null,
            folderTree: [],
            opTargetId: 0,
            opTargetName: '\u6839\u76ee\u5f55'
        },
        upload: {
            currentCount: 0,
            queue: [],
            active: {},
            pendingConflicts: [],
            batchResolution: null,
            refreshNeeded: false,
            interruptedFiles: []
        },
        ai: {
            chatHistory: [],
            currentChatId: null,
            generatedTitles: {},
            titleFailCount: {},
            lastSentMsg: '',
            markedLoaded: false
        },
        preview: {
            fileId: 0,
            fileList: null,
            fileIndex: -1,
            audio: null,
            video: null,
            imageLoads: []
        },
        ui: {
            isTouchDevice: ('ontouchstart' in window) || (navigator.maxTouchPoints > 0),
            rubberBand: { active: false, drag: false }
        }
    };

    var listeners = {};

    function get(path) {
        var keys = path.split('.');
        var obj = state;
        for (var i = 0; i < keys.length; i++) {
            if (obj == null) return undefined;
            obj = obj[keys[i]];
        }
        return obj;
    }

    function _shallowClone(value) {
        if (Array.isArray(value)) {
            return value.slice();
        }
        if (value && typeof value === 'object') {
            return Object.assign({}, value);
        }
        return value;
    }

    function set(path, value) {
        var keys = path.split('.');
        var lastKey = keys.pop();
        var obj = state;
        for (var i = 0; i < keys.length; i++) {
            if (obj[keys[i]] == null) obj[keys[i]] = {};
            obj = obj[keys[i]];
        }
        var oldValue = obj[lastKey];
        var newValue = _shallowClone(value);
        obj[lastKey] = newValue;
        _version++;
        emit(path, newValue, oldValue);
    }

    function update(path, updaterFn) {
        var current = get(path);
        var newValue = updaterFn(current);
        set(path, newValue);
        return _version;
    }

    /**
     * 对 Set / Array / Object 等可变集合做就地修改后触发订阅。
     *
     * 直接调用 selectedFiles.add(x) / .clear() 等 mutator 不会经过 set()，
     * 订阅者收不到通知。本方法在调用 mutator 后主动 emit，使订阅机制可用。
     *
     * 用法：
     *   Store.mutate('files.selectedIds', function (set) { set.add(id); });
     *
     * 注意：mutatorFn 应只做就地修改，不应返回新值（如需替换请用 set/update）。
     */
    function mutate(path, mutatorFn) {
        var current = get(path);
        mutatorFn(current);
        _version++;
        emit(path, current, current);
    }

    /**
     * subscribe：on 的语义化别名，便于业务代码识别"长期订阅"语义。
     * 返回取消订阅函数。
     */
    function subscribe(path, fn) {
        return on(path, fn);
    }

    function getVersion() {
        return _version;
    }

    function on(path, fn) {
        if (!listeners[path]) listeners[path] = [];
        listeners[path].push(fn);
        return function () { off(path, fn); };
    }

    function off(path, fn) {
        if (!listeners[path]) return;
        listeners[path] = listeners[path].filter(function (f) { return f !== fn; });
    }

    function emit(path, value, oldValue) {
        if (!listeners[path]) return;
        listeners[path].forEach(function (fn) {
            try { fn(value, oldValue, path); } catch (e) { console.error('[Store] listener error:', e); }
        });
    }

    function notify(path) {
        emit(path, get(path), undefined);
    }

    function defineAlias(globalName, storePath) {
        var keys = storePath.split('.');
        Object.defineProperty(window, globalName, {
            get: function () {
                var obj = state;
                for (var i = 0; i < keys.length; i++) {
                    if (obj == null) return undefined;
                    obj = obj[keys[i]];
                }
                return obj;
            },
            set: function (v) {
                var k = keys.slice();
                var last = k.pop();
                var obj = state;
                for (var i = 0; i < k.length; i++) {
                    if (obj[k[i]] == null) obj[k[i]] = {};
                    obj = obj[k[i]];
                }
                var old = obj[last];
                obj[last] = v;
                _version++;
                emit(storePath, v, old);
            },
            configurable: true,
            enumerable: true
        });
    }

    defineAlias('currentParentId', 'files.parentId');
    defineAlias('currentView', 'files.view');
    defineAlias('currentSort', 'files.sort');
    defineAlias('currentSortOrder', 'files.sortOrder');
    defineAlias('selectedFiles', 'files.selectedIds');
    defineAlias('contextFileId', 'files.contextFileId');
    defineAlias('contextFileData', 'files.contextFileData');
    defineAlias('isFirstLoad', 'files.isFirstLoad');
    defineAlias('currentFileList', 'files.list');
    defineAlias('isTouchDevice', 'ui.isTouchDevice');
    defineAlias('fileListAbort', 'files.abortController');
    defineAlias('folderTreeData', 'files.folderTree');
    defineAlias('fileOpTargetId', 'files.opTargetId');
    defineAlias('fileOpTargetName', 'files.opTargetName');
    defineAlias('_rubberBand', 'ui.rubberBand');

    defineAlias('currentUploadCount', 'upload.currentCount');
    defineAlias('uploadQueue', 'upload.queue');
    defineAlias('activeUploads', 'upload.active');
    defineAlias('pendingConflicts', 'upload.pendingConflicts');
    defineAlias('batchConflictResolution', 'upload.batchResolution');
    defineAlias('_uploadRefreshNeeded', 'upload.refreshNeeded');
    defineAlias('interruptedFiles', 'upload.interruptedFiles');

    defineAlias('aiChatHistory', 'ai.chatHistory');
    defineAlias('currentChatId', 'ai.currentChatId');
    defineAlias('_lastSentMsg', 'ai.lastSentMsg');
    defineAlias('_markedLoaded', 'ai.markedLoaded');

    defineAlias('previewState', 'preview');

    return {
        state: state,
        get: get,
        set: set,
        update: update,
        mutate: mutate,
        getVersion: getVersion,
        on: on,
        off: off,
        subscribe: subscribe,
        notify: notify,
        defineAlias: defineAlias
    };
})();
