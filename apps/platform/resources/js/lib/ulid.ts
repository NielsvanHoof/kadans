/**
 * Crockford Base32 ULID (26 chars). Matches Laravel's `ulid` validation rule.
 * No dependency — enough for client-generated field_key values.
 */
const ENCODING = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

function encodeTime(timestamp: number, length: number): string {
    let value = timestamp;
    let output = '';

    for (let i = length; i > 0; i -= 1) {
        const mod = value % 32;
        output = ENCODING[mod] + output;
        value = Math.floor(value / 32);
    }

    return output;
}

function encodeRandom(length: number): string {
    let output = '';

    for (let i = 0; i < length; i += 1) {
        output += ENCODING[Math.floor(Math.random() * 32)];
    }

    return output;
}

/** Generate a new ULID string for questionnaire field_key values. */
export function createUlid(): string {
    return encodeTime(Date.now(), 10) + encodeRandom(16);
}
