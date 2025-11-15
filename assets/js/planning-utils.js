(function (root, factory) {
    if (typeof module === 'object' && typeof module.exports === 'object') {
        module.exports = factory();
    } else {
        root.PlanningUtils = factory();
    }
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    function parentKeyFor(parentId) {
        return parentId === null || parentId === undefined || parentId === '' ? 'root' : String(parentId);
    }

    function normalizePlanningItems(rawItems) {
        const itemsById = {};
        const childrenByParent = {};

        if (!Array.isArray(rawItems)) {
            return { itemsById, childrenByParent };
        }

        rawItems.forEach((raw) => {
            if (!raw || typeof raw !== 'object') {
                return;
            }

            const id = Number(raw.id);
            if (!Number.isFinite(id)) {
                return;
            }

            const parentId = raw.parent_id === null || raw.parent_id === undefined ? null : Number(raw.parent_id);
            const normalized = {
                id,
                book_id: Number(raw.book_id),
                parent_id: parentId,
                item_type: raw.item_type || 'scene',
                title: raw.title || 'Untitled',
                synopsis: raw.synopsis || '',
                word_count: Number(raw.word_count) || 0,
                position: Number(raw.position) || 0,
                status: raw.status || 'to_do',
                label: raw.label || '',
                metadata: raw.metadata && typeof raw.metadata === 'object' ? raw.metadata : {},
            };

            itemsById[id] = normalized;
            const key = parentKeyFor(parentId);
            if (!childrenByParent[key]) {
                childrenByParent[key] = [];
            }
            childrenByParent[key].push(id);
        });

        Object.keys(childrenByParent).forEach((key) => {
            childrenByParent[key].sort((a, b) => {
                const itemA = itemsById[a];
                const itemB = itemsById[b];
                const posDiff = (itemA?.position ?? 0) - (itemB?.position ?? 0);
                if (posDiff !== 0) {
                    return posDiff;
                }
                const titleA = (itemA?.title || '').toLowerCase();
                const titleB = (itemB?.title || '').toLowerCase();
                if (titleA < titleB) return -1;
                if (titleA > titleB) return 1;
                return a - b;
            });
        });

        return { itemsById, childrenByParent };
    }

    function getCollection(childrenByParent, parentId) {
        const key = parentKeyFor(parentId);
        const ids = childrenByParent[key] || [];
        return ids.slice();
    }

    function derivePlanningParent(itemsById, itemId) {
        const item = itemsById && itemsById[itemId];
        if (!item) {
            return null;
        }
        if (item.item_type === 'folder' || item.item_type === 'chapter') {
            return item.id;
        }
        return item.parent_id ?? null;
    }

    return {
        normalizePlanningItems,
        getCollection,
        derivePlanningParent,
        parentKeyFor,
    };
});
