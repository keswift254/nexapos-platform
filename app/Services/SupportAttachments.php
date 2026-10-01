<?php

declare(strict_types=1);

namespace Platform\Services;

use PDO;
use InvalidArgumentException;

/** Private image bytes: only the owning shop or authenticated support can read. */
final class SupportAttachments
{
    public static function validate(mixed $items): array
    {
        if (!is_array($items) || count($items) > 4) {
            throw new InvalidArgumentException('Attach up to 4 photos per message.');
        }
        $result = [];
        $total = 0;
        foreach ($items as $item) {
            $encoded = is_array($item) ? ($item['data'] ?? null) : null;
            if (!is_string($encoded) || strlen($encoded) > 2800000) {
                throw new InvalidArgumentException('Each photo must be 2 MB or smaller.');
            }
            $bytes = base64_decode($encoded, true);
            if ($bytes === false || strlen($bytes) === 0 || strlen($bytes) > 2097152) {
                throw new InvalidArgumentException('Each photo must be 2 MB or smaller.');
            }
            $info = @getimagesizefromstring($bytes);
            $mime = $info['mime'] ?? '';
            if (!$info || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)
                || $info[0] * $info[1] > 16000000) {
                throw new InvalidArgumentException('Use a PNG, JPEG or WebP photo up to 16 megapixels.');
            }
            $total += strlen($bytes);
            if ($total > 4194304) {
                throw new InvalidArgumentException('Photos in one message must total 4 MB or less.');
            }
            $result[] = ['bytes' => $bytes, 'mime' => $mime];
        }
        return $result;
    }

    // Call inside the message transaction. Lock serializes the per-ticket quota.
    public static function save(PDO $pdo, int $ticketId, int $messageId, array $images): void
    {
        if (!$images) return;
        $lock = $pdo->prepare('SELECT id FROM support_tickets WHERE id = ? FOR UPDATE');
        $lock->execute([$ticketId]);
        $size = $pdo->prepare('SELECT COALESCE(SUM(byte_size), 0) FROM support_attachments WHERE ticket_id = ?');
        $size->execute([$ticketId]);
        $added = array_sum(array_map(static fn ($image) => strlen($image['bytes']), $images));
        if ((int) $size->fetchColumn() + $added > 20971520) {
            throw new InvalidArgumentException('This ticket has reached its 20 MB photo limit.');
        }
        $insert = $pdo->prepare('INSERT INTO support_attachments (ticket_id, message_id, mime_type, byte_size, image_data) VALUES (?, ?, ?, ?, ?)');
        foreach ($images as $image) {
            $insert->execute([$ticketId, $messageId, $image['mime'], strlen($image['bytes']), $image['bytes']]);
        }
    }

    public static function withMetadata(PDO $pdo, int $ticketId, array $messages): array
    {
        $query = $pdo->prepare('SELECT id, message_id, mime_type, byte_size FROM support_attachments WHERE ticket_id = ? ORDER BY id');
        $query->execute([$ticketId]);
        $byMessage = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byMessage[(int) $row['message_id']][] = [
                'id' => (int) $row['id'], 'mime_type' => $row['mime_type'], 'byte_size' => (int) $row['byte_size'],
            ];
        }
        foreach ($messages as &$message) {
            $message['attachments'] = $byMessage[(int) $message['id']] ?? [];
        }
        return $messages;
    }
}
