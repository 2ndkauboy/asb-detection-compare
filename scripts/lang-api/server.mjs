/**
 * Local franc-based language detection service, for comparing the LangSpam rule
 * without calling the public API.
 *
 * Mirrors the contract of https://api.pluginkollektiv.org/language/v1/ — POST a
 * JSON body `{ "body": "…" }`, get back `{ "code": "<iso639-3>" }` — and is the
 * same approach the plugin's own E2E tests use.
 *
 * Runs as a cluster of processes sharing one port, because the classifier calls
 * this synchronously once per eligible comment: with N parallel workers there
 * are N requests in flight, and a single Node process would serialise them.
 *
 * The port is fixed at 8080 deliberately. LangSpam calls the endpoint through
 * wp_safe_remote_post(), and wp_http_validate_url() only permits ports 80, 443
 * and 8080 — anything else is rejected before a request is made.
 *
 * Environment:
 *   ASB_LANG_API_WORKERS  number of processes (default 4)
 */
import cluster from 'cluster';
import http from 'http';
import { franc } from 'franc';

const PORT = 8080;
const WORKERS = Math.max( 1, parseInt( process.env.ASB_LANG_API_WORKERS || '4', 10 ) );

if ( cluster.isPrimary ) {
	for ( let i = 0; i < WORKERS; i++ ) {
		cluster.fork();
	}
	// Keep the pool at full strength; a crashed worker would otherwise reduce
	// throughput silently and skew a long comparison run.
	cluster.on( 'exit', () => cluster.fork() );
	console.log( `asb-lang-api: ${ WORKERS } workers on :${ PORT }` );
} else {
	http.createServer( ( req, res ) => {
		if ( req.method !== 'POST' ) {
			res.writeHead( 405 );
			res.end();
			return;
		}

		let raw = '';
		req.on( 'data', ( chunk ) => ( raw += chunk ) );
		req.on( 'end', () => {
			try {
				const { body: text } = JSON.parse( raw );
				res.writeHead( 200, { 'Content-Type': 'application/json' } );
				res.end( JSON.stringify( { code: franc( text ) } ) );
			} catch {
				res.writeHead( 400 );
				res.end();
			}
		} );
	} ).listen( PORT );
}
