# Audio Files API Contracts (v1.0)

## Scope and auth

- Protected routes require `Authorization: Bearer <token>`.
- Write endpoints support `Idempotency-Key`.
- Route prefix: `/api/v1/me/projects/{project}/repertoire/{projectSong}/audio-files`.
- Audio files are project-level (any project member can manage, not just the uploader).

---

## Limits

- **File type**: MP3 only (`audio/mpeg`).
- **Max file size**: 10 MB per file.
- **Max files per song**: 3 per project song.
- **Dedup**: SHA-256 hash-based. Uploading the same MP3 to the same song returns 409.

---

## List Audio Files

- **Method**: `GET`
- **Path**: `/audio-files`
- **Response**: `200`

```json
{
  "data": [
    {
      "id": 1,
      "project_song_id": 42,
      "original_filename": "rehearsal.mp3",
      "label": "Rehearsal",
      "file_size_bytes": 4521984,
      "sort_order": 0,
      "created_at": "2026-03-19T10:00:00Z",
      "updated_at": "2026-03-19T10:00:00Z"
    }
  ]
}
```

---

## Upload Audio File

- **Method**: `POST`
- **Path**: `/audio-files`
- **Body**: multipart
  - `file`: MP3 (required, max 10 MB)
  - `label`: string (optional, max 100)
- **Response**: `201` with audio file resource
- **Errors**: `422` (validation), `409` (duplicate SHA-256)

---

## Get Signed Playback URL

- **Method**: `GET`
- **Path**: `/audio-files/{audioFile}/signed-url`
- **Response**: `200`

```json
{
  "url": "https://storage.example.com/audio/1/42/1.mp3?X-Amz-Signature=..."
}
```

- URL TTL: 60 minutes.

---

## Replace Audio File

- **Method**: `POST`
- **Path**: `/audio-files/{audioFile}/replace`
- **Body**: multipart
  - `file`: MP3 (required, max 10 MB)
- **Response**: `200` with updated audio file resource
- Replaces the MP3 file in storage. Keeps the same record ID, sort_order, and label.
- Old file is soft-deleted via trash service.

---

## Update Audio File

- **Method**: `PUT`
- **Path**: `/audio-files/{audioFile}`
- **Body**:

```json
{
  "label": "Studio Mix",
  "sort_order": 1
}
```

- Set `label` to `null` to clear it.
- **Response**: `200` with updated audio file resource

---

## Delete Audio File

- **Method**: `DELETE`
- **Path**: `/audio-files/{audioFile}`
- **Response**: `200`
- File is soft-deleted via trash service.
- Remaining siblings' sort_order values are reindexed.

---

## Storage accounting

Uploads and replacements increment `audio_mp3_bytes` on the user's `account_usage_counters`.
Deletes and replacements decrement accordingly.
