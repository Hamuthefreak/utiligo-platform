<?php
/**
 * Minimal pure-PHP ZIP writer (STORE method, no compression).
 * Used as a fallback when the ZipArchive PHP extension is unavailable
 * (common on free hosts like InfinityFree). No external dependencies —
 * writes the ZIP file format directly per the PKZIP spec.
 */
class SimpleZipWriter
{
    private array $files = [];

    public function addFile(string $path, string $localName): void
    {
        $this->files[] = ['path' => $path, 'name' => $localName];
    }

    public function save(string $zipPath): bool
    {
        $fh = fopen($zipPath, 'wb');
        if (!$fh) return false;

        $centralDirectory = '';
        $offset = 0;
        $dosTime = 0;
        $dosDate = (1 << 9) | (1 << 5) | 1; // valid placeholder date: 1981-01-01

        foreach ($this->files as $f) {
            $data = file_get_contents($f['path']);
            if ($data === false) continue;

            $name = str_replace('\\', '/', $f['name']);
            $crc = crc32($data);
            $size = strlen($data);
            $nameLen = strlen($name);

            // Local file header: sig(4) ver(2) flags(2) method(2) time(2) date(2)
            // crc(4) compSize(4) uncompSize(4) nameLen(2) extraLen(2) + name
            $localHeader = "\x50\x4b\x03\x04"
                . pack('v', 20)      // version needed
                . pack('v', 0)       // flags
                . pack('v', 0)       // compression method (0 = store)
                . pack('v', $dosTime)
                . pack('v', $dosDate)
                . pack('V', $crc)
                . pack('V', $size)   // compressed size
                . pack('V', $size)   // uncompressed size
                . pack('v', $nameLen)
                . pack('v', 0)       // extra field length
                . $name;

            fwrite($fh, $localHeader);
            fwrite($fh, $data);

            // Central directory header: sig(4) verMade(2) verNeeded(2) flags(2)
            // method(2) time(2) date(2) crc(4) compSize(4) uncompSize(4) nameLen(2)
            // extraLen(2) commentLen(2) diskNum(2) intAttr(2) extAttr(4) offset(4) + name
            $centralDirectory .= "\x50\x4b\x01\x02"
                . pack('v', 20)      // version made by
                . pack('v', 20)      // version needed
                . pack('v', 0)       // flags
                . pack('v', 0)       // compression method
                . pack('v', $dosTime)
                . pack('v', $dosDate)
                . pack('V', $crc)
                . pack('V', $size)
                . pack('V', $size)
                . pack('v', $nameLen)
                . pack('v', 0)       // extra field length
                . pack('v', 0)       // comment length
                . pack('v', 0)       // disk number start
                . pack('v', 0)       // internal attributes
                . pack('V', 0)       // external attributes
                . pack('V', $offset) // offset of local header
                . $name;

            $offset += strlen($localHeader) + $size;
        }

        $cdOffset = $offset;
        fwrite($fh, $centralDirectory);
        $cdSize = strlen($centralDirectory);
        $count = count($this->files);

        // End of central directory record: sig(4) diskNum(2) diskWithCd(2)
        // entriesOnDisk(2) totalEntries(2) cdSize(4) cdOffset(4) commentLen(2)
        $endRecord = "\x50\x4b\x05\x06"
            . pack('v', 0)
            . pack('v', 0)
            . pack('v', $count)
            . pack('v', $count)
            . pack('V', $cdSize)
            . pack('V', $cdOffset)
            . pack('v', 0);

        fwrite($fh, $endRecord);
        fclose($fh);
        return true;
    }
}
