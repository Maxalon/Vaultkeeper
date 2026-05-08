// Positive-control fixture for the sandbox test infrastructure.
//
// Builds two flat FormKit lists (no Vue, no Pinia, no app code) sharing
// a drag group, with a deliberately simple structure. The Playwright
// spec drives a drag here first; if THIS doesn't fire, the test
// infrastructure itself is broken and any conclusion drawn from running
// the real harness would be garbage.

import { dragAndDrop } from '@formkit/drag-and-drop'

const log = []
window.__controlLog = log

function record(kind, data) {
  log.push({ kind, ...data })
  const el = document.querySelector('#event-log')
  if (el) el.textContent = JSON.stringify(log, null, 2)
}

const listA = document.querySelector('#list-a')
const listB = document.querySelector('#list-b')

const valuesA = ['apple', 'apricot', 'avocado']
const valuesB = ['banana', 'blueberry']

dragAndDrop({
  parent: listA,
  getValues: () => valuesA,
  setValues: (next) => { valuesA.splice(0, valuesA.length, ...next) },
  config: {
    group: 'control',
    draggingClass: 'dragging',
    onSort: (data) => record('sort', { parent: 'A', position: data.position, values: data.values }),
    onTransfer: (data) => record('transfer-out', { from: 'A', to: data.targetParent.el.id, targetIndex: data.targetIndex }),
  },
})

dragAndDrop({
  parent: listB,
  getValues: () => valuesB,
  setValues: (next) => { valuesB.splice(0, valuesB.length, ...next) },
  config: {
    group: 'control',
    draggingClass: 'dragging',
    onSort: (data) => record('sort', { parent: 'B', position: data.position, values: data.values }),
    onTransfer: (data) => record('transfer-in', { from: data.sourceParent.el.id, to: 'B', targetIndex: data.targetIndex }),
  },
})
