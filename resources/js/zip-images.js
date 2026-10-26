/**
 * The photos inside a ZIP, read in the browser.
 *
 * "Photos en masse" takes a ZIP of product photos as it is, instead of asking
 * the shop to unzip it first. The archive never goes to the server whole —
 * a 300 MB ZIP would hit a shared host's upload limit — only the photos do,
 * one batch at a time, like photos dropped one by one.
 *
 * Reads the central directory, then each entry from its own slice of the
 * file, so a large archive is not loaded into memory at once. Inflates with
 * the browser's DecompressionStream; no library.
 */
const IMAGE = /\.(jpe?g|png|webp|gif)$/i;
const MIME = { jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', webp: 'image/webp', gif: 'image/gif' };

export const isZip = (file) => /\.zip$/i.test(file.name) || /zip/.test(file.type);

export const canReadZip = () => typeof DecompressionStream === 'function';

const bytes = async (file, start, end) => new DataView(await file.slice(start, end).arrayBuffer());

/** @returns {Promise<File[]>} the image entries, named after their file name alone */
export async function zipImages(file) {
    if (!canReadZip()) throw new Error('unsupported');

    // End of central directory: in the last 64 KB + 22 bytes.
    const tailStart = Math.max(0, file.size - 65_557);
    const tail = await bytes(file, tailStart, file.size);
    let eocd = -1;
    for (let i = tail.byteLength - 22; i >= 0; i--) {
        if (tail.getUint32(i, true) === 0x06054b50) { eocd = i; break; }
    }
    if (eocd < 0) throw new Error('not-a-zip');

    const count = tail.getUint16(eocd + 10, true);
    const dirSize = tail.getUint32(eocd + 12, true);
    const dirOffset = tail.getUint32(eocd + 16, true);
    const dir = await bytes(file, dirOffset, dirOffset + dirSize);
    const decoder = new TextDecoder();

    const entries = [];
    for (let p = 0, i = 0; i < count && p + 46 <= dir.byteLength; i++) {
        if (dir.getUint32(p, true) !== 0x02014b50) break;
        const method = dir.getUint16(p + 10, true);
        const compressed = dir.getUint32(p + 20, true);
        const nameLength = dir.getUint16(p + 28, true);
        const extraLength = dir.getUint16(p + 30, true);
        const commentLength = dir.getUint16(p + 32, true);
        const localOffset = dir.getUint32(p + 42, true);
        const path = decoder.decode(new Uint8Array(dir.buffer, dir.byteOffset + p + 46, nameLength));
        p += 46 + nameLength + extraLength + commentLength;

        const name = path.split('/').pop();
        if (!name || name.startsWith('.') || path.includes('__MACOSX') || !IMAGE.test(name)) continue;
        if (method !== 0 && method !== 8) continue; // stored or deflated: what every zip tool writes
        entries.push({ name, method, compressed, localOffset });
    }

    const files = [];
    for (const entry of entries) {
        // The local header repeats the name and may carry its own extra field.
        const local = await bytes(file, entry.localOffset, entry.localOffset + 30);
        const dataStart = entry.localOffset + 30 + local.getUint16(26, true) + local.getUint16(28, true);
        let blob = file.slice(dataStart, dataStart + entry.compressed);
        if (entry.method === 8) {
            blob = await new Response(blob.stream().pipeThrough(new DecompressionStream('deflate-raw'))).blob();
        }
        const extension = entry.name.split('.').pop().toLowerCase();
        files.push(new File([blob], entry.name, { type: MIME[extension] || 'image/jpeg' }));
    }

    return files;
}

window.CastlitZip = { isZip, canReadZip, zipImages };
