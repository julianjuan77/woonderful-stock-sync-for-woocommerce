/**
 * docs/MANUAL.md → src/manual-content.js
 *
 * El manual se escribe en Markdown y se lee en la pantalla Manual. Esto lo convierte una vez,
 * al compilar, para no cargar un parser en el navegador por un texto que no cambia nunca
 * entre dos builds.
 *
 * Cubre el Markdown que usa el manual y nada más: encabezados, párrafos, listas, tablas,
 * bloques de código, citas y líneas. Si hace falta algo nuevo, se suma acá.
 *
 * Uso: npm run manual
 */

const fs = require( 'fs' );
const path = require( 'path' );

const ROOT = path.resolve( __dirname, '..' );
const SOURCE = path.join( ROOT, 'docs', 'MANUAL.md' );
const TARGET = path.join( ROOT, 'src', 'manual-content.js' );

// La URL de assets/manual/ no se conoce al compilar: la pone el navegador (Manual.js).
const MANUAL_URL = '%%WSS_MANUAL_URL%%';

const escape = ( text ) =>
	text
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' );

// El id de un encabezado, para el índice y el enlace directo.
const slug = ( text ) =>
	text
		.toLowerCase()
		.normalize( 'NFD' )
		.replace( /[̀-ͯ]/g, '' )
		.replace( /[^a-z0-9]+/g, '-' )
		.replace( /^-+|-+$/g, '' );

// Lo de adentro de una línea. El código va primero y se guarda aparte: dentro de `code` no
// se interpreta nada, y sin eso un `**` de un ejemplo saldría en negrita.
const inline = ( text ) => {
	const code = [];
	let out = text.replace( /`([^`]+)`/g, ( _, body ) => {
		code.push( body );
		return `\u0000${ code.length - 1 }\u0000`;
	} );

	out = escape( out )
		.replace( /\*\*([^*]+)\*\*/g, '<strong>$1</strong>' )
		.replace( /(^|[\s(])\*([^*]+)\*/g, '$1<em>$2</em>' )
		.replace(
			/\[([^\]]+)\]\(([^)\s]+)\)/g,
			'<a href="$2" target="_blank" rel="noreferrer noopener">$1</a>'
		);

	return out.replace(
		/\u0000(\d+)\u0000/g,
		( _, i ) => `<code>${ escape( code[ i ] ) }</code>`
	);
};

const isListItem = ( line ) => /^\s*(?:[-*]|\d+\.)\s+/.test( line );
const listMarker = ( line ) => ( /^\s*\d+\./.test( line ) ? 'ol' : 'ul' );

// Una tabla: la fila de encabezado, la de guiones, y las de datos.
const renderTable = ( rows ) => {
	const cells = ( row ) =>
		row
			.trim()
			.replace( /^\||\|$/g, '' )
			.split( '|' )
			.map( ( cell ) => cell.trim() );

	const head = cells( rows[ 0 ] );
	const body = rows.slice( 2 ).map( cells );

	// Una tabla sin títulos no dibuja la fila de encabezado.
	const headless = head.every( ( cell ) => '' === cell );
	const th = head
		.map( ( cell ) => `<th>${ inline( cell ) }</th>` )
		.join( '' );
	const tr = body
		.map(
			( row ) =>
				`<tr>${ row
					.map( ( cell ) => `<td>${ inline( cell ) }</td>` )
					.join( '' ) }</tr>`
		)
		.join( '' );

	const thead = headless ? '' : `<thead><tr>${ th }</tr></thead>`;

	return `<div class="wss-manual__table-wrap"><table>${ thead }<tbody>${ tr }</tbody></table></div>`;
};

// Una lista, con sus items ya unidos (cada uno es un párrafo de una línea).
const renderList = ( tag, items ) =>
	`<${ tag }>${ items
		.map( ( item ) => `<li>${ inline( item ) }</li>` )
		.join( '' ) }</${ tag }>`;

const convert = ( markdown ) => {
	const lines = markdown.split( '\n' );
	const out = [];
	const toc = [];
	let i = 0;

	// El `# ` del archivo no entra: la pantalla pone su propia cabecera.
	let titleSeen = false;

	while ( i < lines.length ) {
		const line = lines[ i ];

		if ( '' === line.trim() ) {
			i++;
			continue;
		}

		// Bloque de código.
		if ( line.startsWith( '```' ) ) {
			const body = [];
			i++;
			while ( i < lines.length && ! lines[ i ].startsWith( '```' ) ) {
				body.push( lines[ i ] );
				i++;
			}
			i++;
			out.push( `<pre><code>${ escape( body.join( '\n' ) ) }</code></pre>` );
			continue;
		}

		// Una captura, sola en su línea: `![epígrafe](archivo.png)`. El texto va al epígrafe y
		// el `alt` queda vacío a propósito: el lector de pantalla ya lee el epígrafe, y
		// repetirlo lo diría dos veces.
		const image = line.trim().match( /^!\[([^\]]*)\]\(([^)\s]+)\)$/ );
		if ( image ) {
			i++;
			out.push(
				'<figure class="wss-manual__figure">' +
					`<img src="${ MANUAL_URL }${ escape( image[ 2 ] ) }" alt="" loading="lazy" />` +
					`<figcaption>${ inline( image[ 1 ] ) }</figcaption>` +
					'</figure>'
			);
			continue;
		}

		// Línea divisoria.
		if ( /^---+$/.test( line.trim() ) ) {
			out.push( '<hr />' );
			i++;
			continue;
		}

		// Encabezado.
		const heading = line.match( /^(#{1,4})\s+(.*)$/ );
		if ( heading ) {
			const level = heading[ 1 ].length;
			const text = heading[ 2 ].trim();
			i++;

			if ( 1 === level && ! titleSeen ) {
				titleSeen = true;
				continue;
			}

			const id = slug( text );
			if ( 2 === level ) {
				toc.push( { id, text: text.replace( /\*\*/g, '' ) } );
			}
			out.push( `<h${ level } id="${ id }">${ inline( text ) }</h${ level }>` );
			continue;
		}

		// Cita. Puede traer varios párrafos; las líneas sueltas se unen.
		if ( line.trimStart().startsWith( '>' ) ) {
			const paragraphs = [];
			let current = [];
			while (
				i < lines.length &&
				lines[ i ].trimStart().startsWith( '>' )
			) {
				const body = lines[ i ].trimStart().replace( /^>\s?/, '' );
				if ( '' === body.trim() ) {
					if ( current.length ) {
						paragraphs.push( current.join( ' ' ) );
						current = [];
					}
				} else {
					current.push( body.trim() );
				}
				i++;
			}
			if ( current.length ) {
				paragraphs.push( current.join( ' ' ) );
			}
			const warning = paragraphs.some( ( p ) => p.includes( '⚠️' ) );
			out.push(
				`<blockquote${ warning ? ' class="is-warning"' : '' }>${ paragraphs
					.map( ( p ) => `<p>${ inline( p.replace( '⚠️', '' ).trim() ) }</p>` )
					.join( '' ) }</blockquote>`
			);
			continue;
		}

		// Tabla.
		if (
			line.trimStart().startsWith( '|' ) &&
			lines[ i + 1 ] &&
			/^\s*\|[\s:|-]+\|\s*$/.test( lines[ i + 1 ] )
		) {
			const rows = [];
			while ( i < lines.length && lines[ i ].trimStart().startsWith( '|' ) ) {
				rows.push( lines[ i ] );
				i++;
			}
			out.push( renderTable( rows ) );
			continue;
		}

		// Lista. Una línea indentada que no abre item es la continuación de la anterior.
		if ( isListItem( line ) ) {
			const tag = listMarker( line );
			const items = [];
			while ( i < lines.length ) {
				const current = lines[ i ];
				if ( '' === current.trim() ) {
					// Una línea en blanco corta la lista salvo que siga otro item.
					if ( lines[ i + 1 ] && isListItem( lines[ i + 1 ] ) ) {
						i++;
						continue;
					}
					break;
				}
				if ( isListItem( current ) ) {
					items.push( current.replace( /^\s*(?:[-*]|\d+\.)\s+/, '' ).trim() );
				} else if ( /^\s+/.test( current ) && items.length ) {
					items[ items.length - 1 ] += ' ' + current.trim();
				} else {
					break;
				}
				i++;
			}
			out.push( renderList( tag, items ) );
			continue;
		}

		// Párrafo: hasta la línea en blanco o hasta que empiece otro bloque.
		const paragraph = [];
		while (
			i < lines.length &&
			'' !== lines[ i ].trim() &&
			! lines[ i ].startsWith( '```' ) &&
			! /^#{1,4}\s/.test( lines[ i ] ) &&
			! /^---+$/.test( lines[ i ].trim() ) &&
			! lines[ i ].trimStart().startsWith( '>' ) &&
			! lines[ i ].trimStart().startsWith( '|' ) &&
			! isListItem( lines[ i ] )
		) {
			paragraph.push( lines[ i ].trim() );
			i++;
		}
		if ( paragraph.length ) {
			out.push( `<p>${ inline( paragraph.join( ' ' ) ) }</p>` );
		} else {
			i++;
		}
	}

	return { html: out.join( '\n' ), toc };
};

// El zip del plugin no lleva docs/ (.distignore) pero sí src/, así que al compilar desde el
// zip el archivo generado ya está y no hay nada que convertir. En el repo, en cambio, falta
// el .md sólo si alguien lo borró: eso sí es un error.
if ( ! fs.existsSync( SOURCE ) ) {
	if ( fs.existsSync( TARGET ) ) {
		process.stdout.write(
			'manual: sin docs/MANUAL.md; se usa src/manual-content.js tal como está\n'
		);
		process.exit( 0 );
	}
	process.stderr.write(
		'manual: falta docs/MANUAL.md y no hay src/manual-content.js que usar\n'
	);
	process.exit( 1 );
}

const markdown = fs.readFileSync( SOURCE, 'utf8' );
const { html, toc } = convert( markdown );

const file = `/**
 * El manual, convertido desde docs/MANUAL.md.
 *
 * GENERADO: no editar a mano. Se corrige el .md y se corre \`npm run manual\`.
 */

export const MANUAL_HTML = ${ JSON.stringify( html ) };

export const MANUAL_TOC = ${ JSON.stringify( toc, null, '\t' ) };
`;

fs.writeFileSync( TARGET, file );
process.stdout.write(
	`manual: ${ toc.length } secciones, ${ html.length } caracteres → src/manual-content.js\n`
);
