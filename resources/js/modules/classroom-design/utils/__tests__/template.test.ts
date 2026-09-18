import { test } from 'node:test'
import assert from 'node:assert/strict'
import { classicTemplate } from '../templateRoom'
import { checkObject, openingZones } from '../collision'

for (const n of [8, 15, 20, 24, 30, 40]) {
  test(`hazır şablon: ${n} öğrenci → ${n} geçerli masa + tahta + öğretmen masası`, () => {
    const d = classicTemplate(n)
    const desks = d.objects.filter((o) => o.type === 'desk-single')
    assert.equal(desks.length, n)
    assert.ok(d.objects.some((o) => o.type === 'smartboard'))
    assert.ok(d.objects.some((o) => o.type === 'teacher-desk'))
    const zones = openingZones(d.room, d.openings)
    for (const o of d.objects) assert.ok(checkObject(o, { room: d.room, zones, objects: d.objects }).ok, `${o.type} ${o.no ?? ''} geçerli`)
  })
}
