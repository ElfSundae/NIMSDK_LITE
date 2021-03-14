#!/usr/bin/env php
<?php

$name = 'NIMSDK_LITE';
$repo = 'https://github.com/ElfSundae/NIMSDK_LITE-xcframework.git';

$version = null;
$newVersion = null;

if ($argc > 1) {
    if (preg_match('#^\d+\.\d+\.\d+$#', $argv[1])) {
        $version = $argv[1];
    } else {
        echo "Usage: php {$argv[0]} [<pod version> [<new version>]]".PHP_EOL;
        exit(1);
    }

    if ($argc > 2) {
        $newVersion = $argv[2];
    }
}

(new Builder($name, $repo, $version, $newVersion))->run();

class Helper
{
    /**
     * Create a directory.
     *
     * @param  string  $dir
     * @return bool
     */
    public static function createDir($dir)
    {
        return is_dir($dir) ?: mkdir($dir, 0777, true);
    }

    public static function createNewDir($dir)
    {
        return static::deletePath($dir) ? static::createDir($dir) : false;
    }

    /**
     * Delete a file or directory.
     *
     * @param  string  $path
     * @return bool
     */
    public static function deletePath($path)
    {
        exec('rm -rf "'.$path.'"', $output, $ret);

        return $ret === 0;
    }

    /**
     * Request the URL, return the response content or save the response content
     * to the given file path.
     *
     * @param  string  $url
     * @param  null|string  $path
     * @param  bool  $progress
     * @return string|bool  Return `false` if request failed.
     */
    public static function request($url, $path = null, $progress = false)
    {
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FAILONERROR => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_CONNECTTIMEOUT => 30,
        ];

        if ($path) {
            $fp = fopen($path, 'w');
            if ($fp === false) {
                return false;
            }
            $options[CURLOPT_FILE] = $fp;

            if ($progress) {
                $options[CURLOPT_NOPROGRESS] = false;
            }
        }

        $ch = curl_init();
        if ($ch === false) {
            return false;
        }
        curl_setopt_array($ch, $options);
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (curl_errno($ch) || $code < 200 || $code >= 300) {
            $data = false;
        }
        curl_close($ch);

        if (isset($options[CURLOPT_FILE])) {
            fclose($options[CURLOPT_FILE]);
        }

        return $data;
    }

    /**
     * Download a file from the URL.
     *
     * @param  string  $url
     * @param  string  $path
     * @param  bool  $overwrite
     * @param  bool  $progress
     * @return bool
     */
    public static function downloadFile($url, $path, $progress = true, $overwrite = false)
    {
        if (is_file($path)) {
            if ($overwrite) {
                static::deletePath($path);
            } else {
                return true;
            }
        }

        $tmp = $path.'.downloading';
        if (! static::request($url, $tmp, $progress)) {
            return false;
        }
        if (! rename($tmp, $path)) {
            return false;
        }

        return true;
    }

    /**
     * Extract a file.
     *
     * @param  string  $file
     * @return string|false
     */
    public static function extractFile($file)
    {
        $pathinfo = pathinfo($file);
        $path = $pathinfo['dirname'].'/'.$pathinfo['filename'];
        static::deletePath($path);
        exec(sprintf('unzip -q "%s" -d "%s"', $file, $path), $output, $ret);
        if ($ret !== 0) {
            return false;
        }

        return $path;
    }

    /**
     * Create a xcframework from the given binary framework file.
     *
     * @param  string  $framework
     * @return string|false
     */
    public static function createXcframeworkFromFramework($framework)
    {
        if (! is_file($framework.'/Info.plist')) {
            return false;
        }

        // Strip fat framework
        $platforms = ['iphoneos', 'iphonesimulator'];
        $frameworks = array_filter(
            array_map(function ($platform) use ($framework) {
                return static::stripFramework($framework, $platform);
            }, $platforms)
        );
        if (count($platforms) != count($frameworks)) {
            return false;
        }

        // Create xcframework
        $pathinfo = pathinfo($framework);
        $xcframework = $pathinfo['dirname'].'/'.$pathinfo['filename'].'.xcframework';
        $cmd = sprintf(
            'xcodebuild -create-xcframework -framework %s -output "%s"',
            implode(' -framework ', $frameworks),
            $xcframework
        );
        exec($cmd, $output, $ret);
        foreach ($frameworks as $path) {
            static::deletePath(dirname($path));
        }
        if ($ret !== 0) {
            return false;
        }

        return $xcframework;
    }

    protected static function stripFramework($framework, $platform)
    {
        if (! ($validArchs = static::validArchsForPlatform($platform))) {
            return false;
        }

        $root = $framework.'-'.$platform;
        if (! static::createNewDir($root)) {
            return false;
        }

        exec(sprintf('cp -a "%s" "%s/"', $framework, $root), $output, $ret);
        if ($ret !== 0) {
            static::deletePath($root);

            return false;
        }

        $newFramework = $root.'/'.basename($framework);
        $binary = $newFramework.'/'.pathinfo($newFramework, PATHINFO_FILENAME);
        if (! is_file($binary)) {
            static::deletePath($root);

            return false;
        }

        $currentArchs = static::getArchsForFile($binary);
        $removeArchs = array_diff($currentArchs, $validArchs);

        if ($removeArchs) {
            $cmd = sprintf(
                'xcrun lipo -remove %s "%s" -o "%s"',
                implode(' -remove ', $removeArchs), $binary, $binary
            );
            exec($cmd, $output, $ret);
            if ($ret !== 0) {
                static::deletePath($root);

                return false;
            }
        }

        return $newFramework;
    }

    /**
     * Returns all valid architectures for the platform.
     *
     * @param  string  $platform
     * @return array
     */
    protected static function validArchsForPlatform($platform)
    {
        switch ($platform) {
            case 'iphoneos':
                return ['armv6', 'armv7', 'armv7s', 'armv8', 'arm64', 'arm64e'];

            case 'iphonesimulator':
                return ['i386', 'x86_64'];

            default:
                return [];
        }
    }

    /**
     * Get architectures for the file.
     *
     * @param  string  $file
     * @return array
     */
    protected static function getArchsForFile($file)
    {
        $archs = exec('xcrun lipo -archs "'.$file.'"', $output, $ret);
        if ($ret !== 0) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(' ', $archs))
        ));
    }
}

class Builder
{
    protected $name;
    protected $repo;
    protected $version;
    protected $newVersion;
    protected $workingDir;

    public function __construct($name, $repo, $version, $newVersion = null)
    {
        $this->name = $name;
        $this->repo = $repo;
        $this->version = $version ?: $this->fetchPodLatestVersion($this->name);
        $this->newVersion = $newVersion ?: $this->patchVersion($this->version);

        Helper::createDir($this->workingDir = __DIR__.'/working');
    }

    public function run()
    {
        echo "Build {$this->name} {$this->version} -> {$this->newVersion}".PHP_EOL;

        $spec = $this->fetchPodspec($this->name, $this->version, true);
        $this->buildForPodspec($spec);

        $spec = $this->updatePodspec($spec);
        $this->savePodspec($spec);
    }

    protected function fetchPodLatestVersion($name)
    {
        echo "Fetching the latest version of pod $name...";

        $versionsURL = 'https://cdn.cocoapods.org/all_pods_versions_'
            .$this->podNameShard($name, '_').'.txt';
        $versions = Helper::request($versionsURL);
        if ($versions === false) {
            echo 'request failed'.PHP_EOL;
            exit(11);
        }

        if (! preg_match('#^'.$name.'(/.+)?/([\d.]+)$#m', $versions, $matches)) {
            echo 'error parsing pods versions index'.PHP_EOL;
            exit(12);
        }

        echo $version = array_pop($matches), PHP_EOL;

        return $version;
    }

    protected function podNameShard($name, $seprator = '/')
    {
        return implode($seprator, str_split(substr(md5($name), 0, 3)));
    }

    protected function patchVersion($version)
    {
        $parts = explode('.', $version);
        $lastNumber = array_pop($parts);
        if ($lastNumber == '0') {
            $parts[] = '001';
        } else {
            $parts[] = $lastNumber.'00';
        }

        return implode('.', $parts);
    }

    protected function fetchPodspec($name, $version, $decodeToArray = false)
    {
        $url = 'https://raw.githubusercontent.com/CocoaPods/Specs/master/Specs/'
            .$this->podNameShard($name, '/')
            .'/'.implode('/', [$name, $version, $name.'.podspec.json']);
        $data = Helper::request($url);

        if (! $data) {
            echo "Failed to fetch podspec from $url".PHP_EOL;
            exit(21);
        }

        if ($decodeToArray) {
            $data = json_decode($data, true);
            if (! is_array($data)) {
                echo 'Could not decode podspec'.PHP_EOL;
                exit(22);
            }
        }

        return $data;
    }

    protected function buildForPodspec($spec)
    {
        $src = $this->downloadPodSource($spec);

        foreach (glob($src.'/*/*.framework') as $framework) {
            echo 'Converting '.basename($framework).' to xcframework...';
            $xcframework = Helper::createXcframeworkFromFramework($framework);
            if (! $xcframework) {
                echo 'failed'.PHP_EOL;
                Helper::deletePath($src);
                exit(31);
            } else {
                echo 'done'.PHP_EOL;
            }
            Helper::deletePath($framework);
        }

        $dist = __DIR__.'/'.$this->name;
        Helper::deletePath($dist);
        if (! rename($src, $dist)) {
            echo 'Error: could not move directory.'.PHP_EOL;
            Helper::deletePath($src);
            exit(32);
        }
    }

    /**
     * Download and extract the pod source zip file.
     *
     * @param  array  $spec
     * @return string
     */
    protected function downloadPodSource($spec)
    {
        $url = $spec['source']['http'];
        $pathinfo = pathinfo(parse_url($url, PHP_URL_PATH));
        $to = $this->workingDir.'/'.$pathinfo['filename'].'-'.md5($url)
            .'.'.$pathinfo['extension'];

        echo "Downloading $url to $to...".PHP_EOL;
        if (! Helper::downloadFile($url, $to)) {
            echo 'Download failed.'.PHP_EOL;
            exit(41);
        }

        echo 'Extracting '.basename($to).'...';
        $path = Helper::extractFile($to);
        if ($path === false) {
            echo 'failed'.PHP_EOL;
        } else {
            echo 'done'.PHP_EOL;
        }

        return $path;
    }

    protected function updatePodspec($spec)
    {
        $spec['version'] = $this->newVersion;

        $spec['source'] = [
            'git' => $this->repo,
            'tag' => $this->newVersion,
        ];

        if (isset($spec['vendored_frameworks'])) {
            $spec['vendored_frameworks'] = array_map(function ($value) {
                return preg_replace(
                    '#^(\*\*/)(.+)\.framework$#m',
                    $this->name.'/$1$2.xcframework',
                    $value
                );
            }, (array) $spec['vendored_frameworks']);
        }

        return $spec;
    }

    protected function savePodspec($spec)
    {
        $json = $this->encodePodspecToJson($spec);

        file_put_contents(__DIR__.'/'.$this->name.'.podspec.json', $json.PHP_EOL);
    }

    protected function encodePodspecToJson($spec)
    {
        $json = json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // Reduce the indentation size from 4 spaces to 2, to follow the style
        // of the `.podspec.json` file type.
        return preg_replace_callback('#^ +#m', function ($matches) {
            return str_repeat(' ', strlen($matches[0]) / 2);
        }, $json);
    }
}
