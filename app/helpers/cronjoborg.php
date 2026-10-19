<?php
/**
 * Putting the cron job on cron-job.org, for hosts with no cron of their own.
 *
 * Their API is a small REST service at https://api.cron-job.org, authorised
 * with a bearer token you create under Settings -> API in your cron-job.org
 * account. We only ever touch the one job we created, whose id is kept in
 * settings as cronjob_org_job_id.
 *
 * Nothing here is required: it is an alternative to a cPanel cron entry, not
 * a replacement for it.
 */

/** Is an API key saved? */
function cronjoborg_configured(): bool
{
    return trim((string) setting('cronjob_org_key', '')) !== '';
}

/**
 * One call against their API.
 *
 * @return array{ok: bool, status: int, data: array, error: ?string}
 */
function cronjoborg_call(string $method, string $path, ?array $payload = null): array
{
    $key = trim((string) setting('cronjob_org_key', ''));
    if ($key === '') {
        return ['ok' => false, 'status' => 0, 'data' => [], 'error' => 'No cron-job.org API key saved.'];
    }

    $ch = curl_init('https://api.cron-job.org' . $path);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'OTPFlash/1.0',
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ],
    ];
    if ($payload !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($payload);
    }
    curl_setopt_array($ch, $options);

    $body   = curl_exec($ch);
    $error  = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error !== '') {
        return ['ok' => false, 'status' => 0, 'data' => [], 'error' => 'Could not reach cron-job.org: ' . $error];
    }

    $data = json_decode((string) $body, true);
    $data = is_array($data) ? $data : [];

    if ($status >= 400) {
        $message = (string) ($data['error'] ?? $data['message'] ?? 'HTTP ' . $status);
        if ($status === 401 || $status === 403) {
            $message = 'cron-job.org rejected the API key.';
        }
        return ['ok' => false, 'status' => $status, 'data' => $data, 'error' => $message];
    }

    return ['ok' => true, 'status' => $status, 'data' => $data, 'error' => null];
}

/** The job we created there, or null. */
function cronjoborg_job(): ?array
{
    $id = trim((string) setting('cronjob_org_job_id', ''));
    if ($id === '' || !cronjoborg_configured()) {
        return null;
    }

    $result = cronjoborg_call('GET', '/jobs/' . rawurlencode($id));
    if (!$result['ok']) {
        // The job was deleted on their side - forget the id rather than
        // showing a job that is not there.
        if ($result['status'] === 404) {
            set_setting('cronjob_org_job_id', '');
        }
        return null;
    }

    return $result['data']['jobDetails'] ?? null;
}

/**
 * Create the job, or update it if we already made one.
 *
 * It runs every 5 minutes, which suits the tasks that are due that often;
 * the ones that are not have their own throttle.
 *
 * @return array{ok: bool, message: string}
 */
function cronjoborg_save(string $url): array
{
    $existing = trim((string) setting('cronjob_org_job_id', ''));

    $job = [
        'job' => [
            'url'              => $url,
            'enabled'          => true,
            'saveResponses'    => true,
            'title'            => setting('site_name', 'OTP Flash') . ' cron',
            'requestTimeout'   => 60,
            'schedule'         => [
                'timezone' => 'UTC',
                'hours'    => [-1],
                'mdays'    => [-1],
                'minutes'  => [0, 5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55],
                'months'   => [-1],
                'wdays'    => [-1],
            ],
        ],
    ];

    $result = $existing !== ''
        ? cronjoborg_call('PATCH', '/jobs/' . rawurlencode($existing), $job)
        : cronjoborg_call('PUT', '/jobs', $job);

    if (!$result['ok']) {
        return ['ok' => false, 'message' => (string) $result['error']];
    }

    if ($existing === '') {
        $id = (string) ($result['data']['jobId'] ?? '');
        if ($id === '') {
            return ['ok' => false, 'message' => 'cron-job.org did not return a job id.'];
        }
        set_setting('cronjob_org_job_id', $id);
        return ['ok' => true, 'message' => 'Created job ' . $id . ' at cron-job.org, running every 5 minutes.'];
    }

    return ['ok' => true, 'message' => 'Updated job ' . $existing . ' at cron-job.org.'];
}

/** Remove the job we created. */
function cronjoborg_delete(): array
{
    $id = trim((string) setting('cronjob_org_job_id', ''));
    if ($id === '') {
        return ['ok' => false, 'message' => 'There is no job to remove.'];
    }

    $result = cronjoborg_call('DELETE', '/jobs/' . rawurlencode($id));
    set_setting('cronjob_org_job_id', '');

    return $result['ok'] || $result['status'] === 404
        ? ['ok' => true,  'message' => 'Removed job ' . $id . ' from cron-job.org.']
        : ['ok' => false, 'message' => (string) $result['error']];
}

/** The last few executions, for the monitor. */
function cronjoborg_history(int $limit = 10): array
{
    $id = trim((string) setting('cronjob_org_job_id', ''));
    if ($id === '' || !cronjoborg_configured()) {
        return [];
    }

    $result = cronjoborg_call('GET', '/jobs/' . rawurlencode($id) . '/history');
    if (!$result['ok']) {
        return [];
    }

    return array_slice($result['data']['history'] ?? [], 0, $limit);
}
