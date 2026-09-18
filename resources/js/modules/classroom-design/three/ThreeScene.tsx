import { useCallback, useEffect, useMemo, useRef } from 'react'
import { Canvas, useFrame, useThree, type ThreeEvent } from '@react-three/fiber'
import { Grid, OrbitControls } from '@react-three/drei'
import * as THREE from 'three'
import { RoomEnvironment } from 'three/examples/jsm/environments/RoomEnvironment.js'
import type { OrbitControls as OrbitControlsImpl } from 'three-stdlib'
import { isDesk } from '../catalog'
import { useClassroom } from '../state/classroomStore'
import { registerResolver, useDrag } from '../state/dragStore'
import { useGhosts } from '../state/ghostStore'
import { useSelection } from '../state/selectionStore'
import { cancelGesture, endGesture, gestureActive, startMove, startRotate, updateMove, updateRotate } from '../state/actions'
import type { Vec2 } from '../types'
import { toLocal } from '../utils/collision'
import { bbox, centroid } from '../utils/polygon'
import { newId } from '../utils/doc'
import { useSceneTheme, type SceneTheme } from '../hooks/useSceneTheme'
import { Room3D } from './Room3D'
import { FurnitureLayer } from './Furniture3D'
import { DeskLabels, DistanceLabel, DragPreview, GhostLayer, Gizmo, Measurements3D, SelectionOutlines } from './Overlays3D'
import { setSceneBridge } from './sceneBridge'

/**
 * 3D SAHNE (WebGL, 1 birim = 1 m). Fare: sol tık seç / sürükle taşı, boşlukta sürükle = seçim kutusu,
 * sağ tık + sürükle = kamerayı döndür, Shift/Ctrl + sağ tık ya da orta tık = kaydır, tekerlek = yakınlaştır.
 * Dokunmatik: tek parmak nesnede taşı / boşlukta döndür, iki parmak yakınlaştır + kaydır.
 * frameloop="demand": yalnız bir şey değişince çizilir (boşta GPU harcamaz).
 */

const floorPlane = new THREE.Plane(new THREE.Vector3(0, 1, 0), 0)

function useFloorPicker() {
  const { camera, gl } = useThree()
  const ray = useMemo(() => new THREE.Raycaster(), [])
  const hit = useMemo(() => new THREE.Vector3(), [])
  return useCallback(
    (clientX: number, clientY: number): Vec2 | null => {
      const r = gl.domElement.getBoundingClientRect()
      const ndc = new THREE.Vector2(((clientX - r.left) / r.width) * 2 - 1, -((clientY - r.top) / r.height) * 2 + 1)
      ray.setFromCamera(ndc, camera)
      return ray.ray.intersectPlane(floorPlane, hit) ? { x: hit.x, z: hit.z } : null
    },
    [camera, gl, ray, hit],
  )
}

function snapToCorner(p: Vec2): Vec2 {
  const poly = useClassroom.getState().doc.room.polygon
  for (const v of poly) if (Math.hypot(v.x - p.x, v.z - p.z) < 0.15) return { x: v.x, z: v.z }
  return { x: Math.round(p.x * 100) / 100, z: Math.round(p.z * 100) / 100 }
}

/** Kamera + yörünge denetimi; ilk açılışta kayıtlı kamera ya da odaya sığdırılmış görünüm */
function CameraRig({ controlsRef, frameRef }: { controlsRef: React.RefObject<OrbitControlsImpl | null>; frameRef: React.MutableRefObject<(() => void) | null> }) {
  const { camera, invalidate } = useThree()
  const layoutId = useClassroom((s) => s.layoutId)
  const setCamera = useClassroom((s) => s.setCamera)
  const frame = useCallback(() => {
    const poly = useClassroom.getState().doc.room.polygon
    const b = bbox(poly)
    const c = centroid(poly)
    const s = Math.max(b.w, b.d, 4)
    camera.position.set(c.x + s * 0.35, s * 1.05, b.maxZ + s * 0.85)
    controlsRef.current?.target.set(c.x, 0.4, c.z)
    controlsRef.current?.update()
    invalidate()
  }, [camera, controlsRef, invalidate])
  frameRef.current = frame

  useEffect(() => {
    const saved = useClassroom.getState().camera
    if (saved) {
      camera.position.set(...saved.position)
      controlsRef.current?.target.set(...saved.target)
      controlsRef.current?.update()
      invalidate()
    } else frame()
    // yalnız tasarım değişince
  }, [layoutId]) // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    const c = controlsRef.current
    if (!c) return
    let t: ReturnType<typeof setTimeout>
    const onEnd = () => {
      clearTimeout(t)
      t = setTimeout(() => {
        const p = camera.position
        const tg = c.target
        const r = (n: number) => Math.round(n * 100) / 100
        setCamera({ position: [r(p.x), r(p.y), r(p.z)], target: [r(tg.x), r(tg.y), r(tg.z)] })
      }, 300)
    }
    c.addEventListener('end', onEnd)
    return () => {
      clearTimeout(t)
      c.removeEventListener('end', onEnd)
    }
  }, [camera, controlsRef, setCamera])

  return (
    <OrbitControls
      ref={controlsRef as never}
      makeDefault
      enableDamping={false}
      minDistance={1.2}
      maxDistance={60}
      maxPolarAngle={Math.PI / 2 - 0.02}
      mouseButtons={{ LEFT: undefined as unknown as THREE.MOUSE, MIDDLE: THREE.MOUSE.PAN, RIGHT: THREE.MOUSE.ROTATE }}
      touches={{ ONE: THREE.TOUCH.ROTATE, TWO: THREE.TOUCH.DOLLY_PAN }}
      onChange={() => invalidate()}
    />
  )
}

function Lights({ theme }: { theme: SceneTheme }) {
  const poly = useClassroom((s) => s.doc.room.polygon)
  const b = useMemo(() => bbox(poly), [poly])
  const c = { x: (b.minX + b.maxX) / 2, z: (b.minZ + b.maxZ) / 2 }
  const s = Math.max(b.w, b.d) / 2 + 2
  const light = useRef<THREE.DirectionalLight>(null)
  useEffect(() => {
    const l = light.current
    if (!l) return
    l.target.position.set(c.x, 0, c.z)
    l.target.updateMatrixWorld()
    const cam = l.shadow.camera
    cam.left = -s
    cam.right = s
    cam.top = s
    cam.bottom = -s
    cam.updateProjectionMatrix()
  }, [c.x, c.z, s])
  return (
    <>
      <hemisphereLight args={[theme.dark ? '#c9d6e8' : '#ffffff', theme.dark ? '#2a2f36' : '#b8b0a4', theme.dark ? 0.55 : 0.75]} />
      <ambientLight intensity={theme.dark ? 0.18 : 0.22} />
      <directionalLight
        ref={light}
        position={[c.x + s * 0.6, 9, c.z + s * 0.4]}
        intensity={theme.dark ? 1.2 : 1.55}
        castShadow
        shadow-mapSize={[2048, 2048]}
        shadow-bias={-0.0004}
        shadow-normalBias={0.02}
      />
    </>
  )
}

function Environment() {
  const { gl, scene } = useThree()
  useEffect(() => {
    const pmrem = new THREE.PMREMGenerator(gl)
    const env = pmrem.fromScene(new RoomEnvironment(), 0.04).texture
    scene.environment = env
    scene.environmentIntensity = 0.45
    pmrem.dispose()
    return () => {
      scene.environment = null
      env.dispose()
    }
  }, [gl, scene])
  return null
}

/** Etkileşim: nesne seç/taşı, tutamaklar, seçim kutusu, ölçüm, sürükle-bırak çözücüsü, küçük görsel köprüsü */
function Interaction({ theme, readOnly, containerRef, frameRef }: { theme: SceneTheme; readOnly: boolean; containerRef: React.RefObject<HTMLDivElement | null>; frameRef: React.MutableRefObject<(() => void) | null> }) {
  const { camera, gl, scene, invalidate, controls } = useThree()
  const pick = useFloorPicker()
  const doc = useClassroom((s) => s.doc)
  const setHover = useSelection((s) => s.setHover)
  const ray = useMemo(() => new THREE.Raycaster(), [])
  const frames = useRef<number[]>([])

  useFrame(() => {
    const now = performance.now()
    frames.current.push(now)
    while (frames.current.length && frames.current[0]! < now - 1000) frames.current.shift()
  })

  const setControls = (on: boolean) => {
    if (controls) (controls as unknown as OrbitControlsImpl).enabled = on
  }

  /** İşaretçi bırakılana kadar pencere düzeyinde izle */
  const track = (onMove: (e: PointerEvent) => void, onUp: (e: PointerEvent) => void) => {
    setControls(false)
    const move = (e: PointerEvent) => onMove(e)
    const up = (e: PointerEvent) => {
      window.removeEventListener('pointermove', move)
      window.removeEventListener('pointerup', up)
      window.removeEventListener('pointercancel', up)
      setControls(true)
      onUp(e)
    }
    window.addEventListener('pointermove', move)
    window.addEventListener('pointerup', up)
    window.addEventListener('pointercancel', up)
  }

  const measureClick = (p: Vec2) => {
    const s = useSelection.getState()
    const q = snapToCorner(p)
    if (!s.pendingMeasure) s.setPendingMeasure(q)
    else s.addMeasurement({ id: newId('m'), a: s.pendingMeasure, b: q })
  }

  const onObjectDown = useCallback(
    (id: string, e: ThreeEvent<PointerEvent>) => {
      const ne = e.nativeEvent
      if (ne.button !== 0) return
      e.stopPropagation()
      const sel = useSelection.getState()
      if (sel.tool === 'measure') {
        measureClick({ x: e.point.x, z: e.point.z })
        return
      }
      if (ne.shiftKey || ne.ctrlKey || ne.metaKey) {
        sel.toggle(id)
        return
      }
      if (!sel.selected.includes(id)) sel.select([id])
      if (readOnly) return
      const start = pick(ne.clientX, ne.clientY)
      if (!start) return
      const ids = useSelection.getState().selected
      const sx = ne.clientX
      const sy = ne.clientY
      let started = false
      track(
        (m) => {
          if (!started) {
            if (Math.hypot(m.clientX - sx, m.clientY - sy) < 4) return
            started = startMove(ids, start)
            if (!started) return
          }
          const p = pick(m.clientX, m.clientY)
          if (p) updateMove(p, { free: m.altKey })
        },
        () => {
          if (started) endGesture()
        },
      )
    },
    [pick, readOnly], // eslint-disable-line react-hooks/exhaustive-deps
  )

  const onFloorDown = useCallback(
    (e: ThreeEvent<PointerEvent>) => {
      const ne = e.nativeEvent
      if (ne.button !== 0) return
      const sel = useSelection.getState()
      if (sel.tool === 'measure') {
        e.stopPropagation()
        measureClick({ x: e.point.x, z: e.point.z })
        return
      }
      if (ne.pointerType === 'touch') {
        // dokunmatikte boşluğa dokunma: seçimi kaldır (tek parmak kamerayı döndürür)
        const sx = ne.clientX
        const sy = ne.clientY
        const up = (u: PointerEvent) => {
          window.removeEventListener('pointerup', up)
          if (Math.hypot(u.clientX - sx, u.clientY - sy) < 6) sel.clear()
        }
        window.addEventListener('pointerup', up)
        return
      }
      e.stopPropagation()
      const rect = containerRef.current?.getBoundingClientRect()
      if (!rect) return
      const x0 = ne.clientX - rect.left
      const y0 = ne.clientY - rect.top
      const additive = ne.shiftKey || ne.ctrlKey || ne.metaKey
      let moved = false
      track(
        (m) => {
          const x1 = m.clientX - rect.left
          const y1 = m.clientY - rect.top
          if (!moved && Math.hypot(x1 - x0, y1 - y0) < 5) return
          moved = true
          useSelection.getState().setMarquee({ x0, y0, x1, y1 })
        },
        (u) => {
          const s = useSelection.getState()
          s.setMarquee(null)
          if (!moved) {
            if (!additive) s.clear()
            return
          }
          const x1 = u.clientX - rect.left
          const y1 = u.clientY - rect.top
          const [lx, hx] = [Math.min(x0, x1), Math.max(x0, x1)]
          const [ly, hy] = [Math.min(y0, y1), Math.max(y0, y1)]
          const d = useClassroom.getState().doc
          const v = new THREE.Vector3()
          const ids = d.order.filter((id) => {
            const o = d.objects[id]!
            v.set(o.x, 0.4, o.z).project(camera)
            const px = ((v.x + 1) / 2) * rect.width
            const py = ((1 - v.y) / 2) * rect.height
            return v.z < 1 && px >= lx && px <= hx && py >= ly && py <= hy
          })
          s.select(ids, additive)
        },
      )
    },
    [camera, containerRef], // eslint-disable-line react-hooks/exhaustive-deps
  )

  const onOpeningDown = useCallback((id: string, e: ThreeEvent<PointerEvent>) => {
    if (e.nativeEvent.button !== 0) return
    if (useSelection.getState().tool === 'measure') return
    e.stopPropagation()
    useSelection.getState().selectOpening(id)
  }, [])

  const gizmo = useMemo(
    () => ({
      onMoveAxis: (axis: 'x' | 'z', e: ThreeEvent<PointerEvent>) => {
        if (readOnly) return
        const ne = e.nativeEvent
        const start = pick(ne.clientX, ne.clientY)
        const ids = useSelection.getState().selected
        if (!start || !startMove(ids, start, axis)) return
        track(
          (m) => {
            const p = pick(m.clientX, m.clientY)
            if (p) updateMove(p, { free: m.altKey })
          },
          () => endGesture(),
        )
      },
      onRotate: (e: ThreeEvent<PointerEvent>) => {
        if (readOnly) return
        const ne = e.nativeEvent
        const id = useSelection.getState().selected[0]
        const start = pick(ne.clientX, ne.clientY)
        if (!id || !start || !startRotate(id, start)) return
        track(
          (m) => {
            const p = pick(m.clientX, m.clientY)
            if (p) updateRotate(p, { fine: m.shiftKey })
          },
          () => endGesture(),
        )
      },
    }),
    [pick, readOnly], // eslint-disable-line react-hooks/exhaustive-deps
  )

  // Esc: süren hareketi iptal
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && gestureActive()) cancelGesture()
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [])

  // Kütüphaneden/öğrenci listesinden bırakma çözücüsü
  useEffect(
    () =>
      registerResolver('3d', {
        el: () => containerRef.current,
        resolve: (cx, cy) => {
          const r = gl.domElement.getBoundingClientRect()
          const ndc = new THREE.Vector2(((cx - r.left) / r.width) * 2 - 1, -((cy - r.top) / r.height) * 2 + 1)
          ray.setFromCamera(ndc, camera)
          const pickables: THREE.Object3D[] = []
          scene.traverse((o) => {
            if (o.userData.pickable) pickables.push(o)
          })
          let objectId: string | null = null
          let seat: number | null = null
          for (const h of ray.intersectObjects(pickables, true)) {
            let node: THREE.Object3D | null = h.object
            while (node && !node.userData.pickable) node = node.parent
            const ids = node?.userData.ids as string[] | undefined
            const id = h.instanceId !== undefined ? ids?.[h.instanceId] : ids?.[0]
            if (id) {
              objectId = id
              const o = useClassroom.getState().doc.objects[id]
              if (o && isDesk(o)) {
                const local = toLocal(o, h.point.x, h.point.z)
                seat = o.type === 'desk-double' ? (local.x < 0 ? 0 : 1) : 0
              }
              break
            }
          }
          const p = new THREE.Vector3()
          const point = ray.ray.intersectPlane(floorPlane, p) ? { x: p.x, z: p.z } : null
          return { point, objectId, seat }
        },
      }),
    [camera, gl, scene, ray, containerRef],
  )

  // Küçük görsel + görünüm köprüsü
  useEffect(() => {
    setSceneBridge({
      fps: () => frames.current.length,
      project: (x: number, z: number, y = 0) => {
        const r = gl.domElement.getBoundingClientRect()
        const v = new THREE.Vector3(x, y, z).project(camera)
        if (v.z > 1) return null
        return { x: r.left + ((v.x + 1) / 2) * r.width, y: r.top + ((1 - v.y) / 2) * r.height }
      },
      bench: (n = 30) => {
        const ctx = gl.getContext()
        const t0 = performance.now()
        for (let i = 0; i < n; i++) gl.render(scene, camera)
        ctx.finish()
        return Math.round(((performance.now() - t0) / n) * 10) / 10
      },
      info: () => ({
        webgl2: gl.capabilities.isWebGL2,
        calls: gl.info.render.calls,
        triangles: gl.info.render.triangles,
        geometries: gl.info.memory.geometries,
        textures: gl.info.memory.textures,
      }),
      frame: () => frameRef.current?.(),
      capture: (width = 480, height = 300) => {
        const poly = useClassroom.getState().doc.room.polygon
        const b = bbox(poly)
        const c = centroid(poly)
        const s = Math.max(b.w, b.d, 4)
        const canvas = gl.domElement
        if (!canvas.width || !canvas.height) return null
        const cam = new THREE.PerspectiveCamera(38, canvas.width / canvas.height, 0.1, 200)
        cam.position.set(c.x + s * 0.55, s * 1.0, b.maxZ + s * 0.7)
        cam.lookAt(c.x, 0, c.z)
        const hidden: THREE.Object3D[] = []
        const walls: [THREE.MeshStandardMaterial, number, boolean][] = []
        const tmp = new THREE.Vector3()
        scene.traverse((o) => {
          if (o.userData.helper && o.visible) {
            hidden.push(o)
            o.visible = false
          }
          if (o.userData.wall !== undefined) {
            const m = (o as THREE.Mesh).material as THREE.MeshStandardMaterial
            walls.push([m, m.opacity, m.depthWrite])
            const geo = (o as THREE.Mesh).geometry
            geo.computeBoundingBox()
            const center = geo.boundingBox!.getCenter(new THREE.Vector3())
            const facing = tmp.subVectors(cam.position, center).setY(0).normalize()
            const inward = new THREE.Vector3(c.x - center.x, 0, c.z - center.z).normalize()
            const front = facing.dot(inward) < -0.2
            m.opacity = front ? 0.12 : 1
            m.depthWrite = !front
          }
        })
        gl.render(scene, cam)
        const out = document.createElement('canvas')
        out.width = width
        out.height = height
        const g = out.getContext('2d')!
        const k = Math.max(width / canvas.width, height / canvas.height)
        const sw = width / k
        const sh = height / k
        g.drawImage(canvas, (canvas.width - sw) / 2, (canvas.height - sh) / 2, sw, sh, 0, 0, width, height)
        hidden.forEach((o) => (o.visible = true))
        walls.forEach(([m, op, dw]) => {
          m.opacity = op
          m.depthWrite = dw
        })
        invalidate()
        try {
          return out.toDataURL('image/jpeg', 0.84)
        } catch {
          return null
        }
      },
    })
    return () => setSceneBridge(null)
  }, [camera, controls, gl, scene, invalidate, frameRef])

  // Akıllı yerleşim önizlemesi açıkken yerini bırakacak öğrenci masaları gizlenir
  const previewing = useGhosts((s) => s.list.length > 0)
  const objects = useMemo(() => doc.order.map((id) => doc.objects[id]!).filter((o) => o && !(previewing && isDesk(o))), [doc.order, doc.objects, previewing])
  const selectedOpening = useSelection((s) => s.openingId)

  return (
    <>
      <Room3D room={doc.room} openings={doc.openings} selectedOpening={selectedOpening} onFloorDown={onFloorDown} onOpeningDown={onOpeningDown} />
      <FurnitureLayer objects={objects} ceiling={doc.room.ceiling} onDown={onObjectDown} onHover={setHover} />
      <SelectionOutlines theme={theme} />
      {!readOnly && <Gizmo theme={theme} handlers={gizmo} />}
      <DistanceLabel theme={theme} />
      <DragPreview theme={theme} />
      <GhostLayer />
      <Measurements3D theme={theme} />
      <DeskLabels />
      {/* odanın dışındaki zemin: boşluğa tıklama/seçim kutusu için */}
      <mesh rotation={[-Math.PI / 2, 0, 0]} position={[0, -0.012, 0]} onPointerDown={onFloorDown} receiveShadow>
        <planeGeometry args={[400, 400]} />
        <meshStandardMaterial color={theme.ground} roughness={1} />
      </mesh>
    </>
  )
}

function GridLayer({ theme }: { theme: SceneTheme }) {
  const grid = useClassroom((s) => s.settings.grid)
  const poly = useClassroom((s) => s.doc.room.polygon)
  const c = useMemo(() => bbox(poly), [poly])
  return (
    <group userData={{ helper: true }}>
      <Grid
        position={[c.minX, 0.003, c.minZ]}
        args={[200, 200]}
        cellSize={grid}
        cellThickness={0.6}
        cellColor={theme.gridCell}
        sectionSize={1}
        sectionThickness={1}
        sectionColor={theme.gridSection}
        fadeDistance={45}
        fadeStrength={1.5}
        infiniteGrid
        followCamera={false}
      />
    </group>
  )
}

function Background({ color }: { color: string }) {
  const { scene, invalidate } = useThree()
  useEffect(() => {
    scene.background = new THREE.Color(color)
    invalidate()
  }, [color, scene, invalidate])
  return null
}

export function ThreeScene({ readOnly = false, className }: { readOnly?: boolean; className?: string }) {
  const theme = useSceneTheme()
  const containerRef = useRef<HTMLDivElement>(null)
  const controlsRef = useRef<OrbitControlsImpl | null>(null)
  const marquee = useSelection((s) => s.marquee)
  const tool = useSelection((s) => s.tool)
  const dragging = useDrag((s) => s.payload !== null)
  const frameRef = useRef<(() => void) | null>(null)

  return (
    <div
      ref={containerRef}
      className={className}
      style={{ touchAction: 'none', cursor: tool === 'measure' ? 'crosshair' : dragging ? 'copy' : undefined }}
      onContextMenu={(e) => e.preventDefault()}
      data-testid="scene-3d"
    >
      <Canvas
        shadows
        frameloop="demand"
        dpr={[1, 2]}
        camera={{ fov: 45, near: 0.05, far: 400, position: [8, 9, 14] }}
        gl={{ antialias: true, powerPreference: 'high-performance' }}
        onCreated={({ gl }) => {
          gl.toneMapping = THREE.ACESFilmicToneMapping
          gl.toneMappingExposure = 1.05
        }}
      >
        <Background color={theme.background} />
        <Environment />
        <Lights theme={theme} />
        <CameraRig controlsRef={controlsRef} frameRef={frameRef} />
        <GridLayer theme={theme} />
        <Interaction theme={theme} readOnly={readOnly} containerRef={containerRef} frameRef={frameRef} />
      </Canvas>
      {marquee && (
        <div
          className="pointer-events-none absolute border border-accent bg-accent/10"
          style={{
            left: Math.min(marquee.x0, marquee.x1),
            top: Math.min(marquee.y0, marquee.y1),
            width: Math.abs(marquee.x1 - marquee.x0),
            height: Math.abs(marquee.y1 - marquee.y0),
          }}
        />
      )}
    </div>
  )
}

