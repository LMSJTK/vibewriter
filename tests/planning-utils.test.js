const assert = require('node:assert/strict');
const {
    normalizePlanningItems,
    getCollection,
    derivePlanningParent,
    parentKeyFor
} = require('../assets/js/planning-utils.js');

const sampleItems = [
    {
        id: 3,
        book_id: 1,
        parent_id: null,
        item_type: 'chapter',
        title: 'Chapter Two',
        synopsis: '',
        word_count: 0,
        position: 5,
        status: 'to_do',
        label: '',
        metadata: {}
    },
    {
        id: 1,
        book_id: 1,
        parent_id: null,
        item_type: 'chapter',
        title: 'Chapter One',
        synopsis: '',
        word_count: 0,
        position: 1,
        status: 'in_progress',
        label: '',
        metadata: {}
    },
    {
        id: 2,
        book_id: 1,
        parent_id: 1,
        item_type: 'scene',
        title: 'Scene A',
        synopsis: '',
        word_count: 450,
        position: 2,
        status: 'done',
        label: 'draft',
        metadata: { pov: 'Sam' }
    },
    {
        id: 4,
        book_id: 1,
        parent_id: 1,
        item_type: 'scene',
        title: 'Scene B',
        synopsis: '',
        word_count: 600,
        position: 1,
        status: 'to_do',
        label: '',
        metadata: {}
    }
];

const { itemsById, childrenByParent } = normalizePlanningItems(sampleItems);

assert.equal(Object.keys(itemsById).length, 4, 'should index all items');
assert.deepEqual(getCollection(childrenByParent, null), [1, 3], 'root order uses position sort');
assert.deepEqual(getCollection(childrenByParent, 1), [4, 2], 'children sorted by position');

assert.equal(derivePlanningParent(itemsById, 1), 1, 'folder/chapter resolves to itself');
assert.equal(derivePlanningParent(itemsById, 2), 1, 'scene resolves to parent id');
assert.equal(derivePlanningParent(itemsById, 3), 3, 'independent chapter resolves to itself');

assert.equal(parentKeyFor(null), 'root', 'null parent uses root key');
assert.equal(parentKeyFor(5), '5', 'numeric parents convert to string');

console.log('planning-utils tests passed');
