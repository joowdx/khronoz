// Inertia SSR renderer.
//
// `bootstrap/ssr/ssr.js` starts an HTTP server on 127.0.0.1:$INERTIA_SSR_PORT as
// a side effect of being imported, and config/inertia.php routes page renders
// there.
// node:cluster forks SSR_WORKERS children sharing that listening socket.
//
// Node builtins only: the runtime stage ships no node_modules.
import cluster from 'node:cluster'
import process from 'node:process'

const BUNDLE = process.env.SSR_BUNDLE || '/app/bootstrap/ssr/ssr.js'
const WORKERS = Math.max(1, Number.parseInt(process.env.SSR_WORKERS || '1', 10) || 1)

// One renderer needs no primary; import the bundle in this process.
if (!cluster.isPrimary || WORKERS === 1) {
    await import(BUNDLE)
} else {
    // A bundle that cannot load dies on every fork, so unconditional respawn
    // would keep the primary alive over a renderer that never serves. Three
    // deaths inside 5s and the primary exits 1.
    const FAST_FAIL_MS = 5000
    const FAST_FAIL_LIMIT = 3

    const started = new Map()
    let fastFailures = 0
    let stopping = false

    const spawn = () => {
        const worker = cluster.fork()
        started.set(worker.id, Date.now())
    }

    cluster.on('exit', (worker, code, signal) => {
        const age = Date.now() - (started.get(worker.id) ?? 0)
        started.delete(worker.id)

        if (stopping) {
            return
        }

        if (age < FAST_FAIL_MS && ++fastFailures >= FAST_FAIL_LIMIT) {
            console.error(`ssr: ${FAST_FAIL_LIMIT} renderers died within ${FAST_FAIL_MS}ms of starting, giving up`)
            process.exit(1)
        }

        console.error(`ssr: renderer ${worker.process.pid} exited (code=${code}, signal=${signal}), replacing it`)
        spawn()
    })

    // runit signals the primary only; forward SIGTERM so children close their
    // listeners instead of being orphaned.
    const stop = (signal) => {
        if (stopping) {
            return
        }

        stopping = true

        for (const worker of Object.values(cluster.workers || {})) {
            worker.kill(signal)
        }
    }

    process.on('SIGTERM', () => stop('SIGTERM'))
    process.on('SIGINT', () => stop('SIGINT'))

    for (let i = 0; i < WORKERS; i++) {
        spawn()
    }
}
