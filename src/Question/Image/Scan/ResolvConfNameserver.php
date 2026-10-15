<?php

declare(strict_types=1);

namespace App\Question\Image\Scan;

/**
 * The first IPv4 nameserver in resolv.conf. Anything else fails closed.
 */
final class ResolvConfNameserver
{
    public function __construct(
        private readonly string $path = '/etc/resolv.conf',
    ) {
    }

    public function first(): string
    {
        if (!is_file($this->path)) {
            return '';
        }
        $handle = fopen($this->path, 'r');
        if (!\is_resource($handle)) {
            return '';
        }
        stream_set_timeout($handle, 2);
        try {
            $data = fread($handle, 4096);
            $meta = stream_get_meta_data($handle);
        } finally {
            fclose($handle);
        }
        if (!\is_string($data) || $meta['timed_out']) {
            return '';
        }
        if (1 !== preg_match('/^nameserver[ \t]+([0-9]{1,3}(?:\.[0-9]{1,3}){3})/m', $data, $matches)) {
            return '';
        }
        foreach (explode('.', $matches[1]) as $octet) {
            if ((int) $octet > 255) {
                return '';
            }
        }

        return $matches[1];
    }
}
