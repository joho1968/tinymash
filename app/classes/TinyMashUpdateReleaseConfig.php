<?php
namespace app\classes;

final class TinyMashUpdateReleaseConfig {
    public const ORIGIN = 'https://tinymashreleases.joho.se';
    public const INDEX_URL = self::ORIGIN . '/stable/index.json';
    public const INDEX_SIGNATURE_URL = self::ORIGIN . '/stable/index.json.sig';
    public const TRUSTED_KEYS = [
        'release-2026-01' => '6B3BTjW1g1L1TnsFxIoT4JfpUDmw+4tavYPqyI6oY0Y=',
    ];
}
