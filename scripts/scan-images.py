"""Fail-closed CI image gate. No live services, credentials or quotas.

The fixed public images are inspected/scanned; version CLIs have no networking.
Raw image configuration stays in a private temporary directory, not CI artifacts.
"""
import argparse
from datetime import datetime, timedelta, timezone
import json
from pathlib import Path
import re
import subprocess
import tempfile


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--allow-local-image-scan', action='store_true')
    parser.add_argument('--cache-directory', type=Path, help='Optional existing local DB cache; freshness is still mandatory')
    args = parser.parse_args()
    if not args.allow_local_image_scan:
        parser.error('Explicit --allow-local-image-scan required')
    if not __debug__:
        raise SystemExit('Assertions required; optimized Python refused')
    root = Path(__file__).resolve().parents[1]
    output = root / '.security'
    output.mkdir(exist_ok=False)

    def command(*parts):
        result = subprocess.run(parts, text=True, capture_output=True, timeout=600, check=False)
        assert result.returncode == 0, 'Image scan/tool failed; raw output withheld'
        return result.stdout.strip()

    def stamp(value):
        return datetime.fromisoformat(value.replace('Z', '+00:00'))

    images = {'web': ('speedtest-web:0.1.0', 50),
              'redis': ('speedtest-redis:0.1.0', 19),
              'edge': ('speedtest-edge:security-candidate', 10)}
    summaries = []
    blocked = []
    with tempfile.TemporaryDirectory(prefix='speedtest-scan-') as directory:
        cache = args.cache_directory.resolve() if args.cache_directory else Path(directory) / 'cache'
        metadata = cache / 'db/metadata.json'
        fresh = metadata.is_file() and stamp(json.loads(metadata.read_text())['NextUpdate']) > datetime.now(timezone.utc)
        if not fresh:
            command('trivy', 'image', '--quiet', '--cache-dir', str(cache), '--download-db-only', '--timeout', '5m')
        database = json.loads((cache / 'db/metadata.json').read_text())
        assert database['Version'] == 2
        for role, (image, minimum) in images.items():
            identifier = command('docker', 'image', 'inspect', '-f', '{{.Id}}', image)
            raw = Path(directory) / (role + '.json')
            archive = Path(directory) / (role + '.tar')
            command('docker', 'image', 'save', '--output', str(archive), image)
            common = ('trivy', 'image', '--quiet', '--cache-dir', str(cache), '--skip-db-update',
                      '--skip-java-db-update', '--offline-scan', '--parallel', '2', '--timeout', '3m')
            command(*common, '--scanners', 'vuln', '--format', 'json', '--output', str(raw), '--input', str(archive))
            report = json.loads(raw.read_text())
            now = datetime.now(timezone.utc)
            assert report['Metadata']['ImageID'] == identifier
            assert command('docker', 'image', 'inspect', '-f', '{{.Id}}', image) == identifier
            assert stamp(database['UpdatedAt']) <= stamp(database['DownloadedAt']) <= stamp(report['CreatedAt']) <= now + timedelta(minutes=5)
            assert now < stamp(database['NextUpdate']), 'Expired DB must not pass'
            results = report.get('Results', [])
            os_results = [item for item in results if item.get('Class') == 'os-pkgs']
            assert len(os_results) == 1 and os_results[0]['Type'] == 'alpine'
            assert len(os_results[0].get('Packages', [])) >= minimum, 'Incomplete OS inventory'
            records = [{key: entry.get(key, '') for key in (
                'VulnerabilityID', 'PkgName', 'InstalledVersion', 'FixedVersion', 'Severity')}
                for item in results for entry in item.get('Vulnerabilities', [])]
            for entry in records:
                assert entry['Severity'] in ('UNKNOWN', 'LOW', 'MEDIUM', 'HIGH', 'CRITICAL')
                if entry['Severity'] in ('HIGH', 'CRITICAL') or (entry['Severity'] == 'UNKNOWN' and entry['FixedVersion']):
                    blocked.append({'role': role, **entry})
            command(*common, '--format', 'cyclonedx', '--output', str(output / (role + '.cdx.json')), '--input', str(archive))
            summaries.append({'role': role, 'imageID': identifier, 'osPackages': len(os_results[0]['Packages']), 'records': records})

        # OS inventories miss these manually compiled executables/extensions.
        php = command('docker', 'run', '--rm', '--network=none', '--read-only', '--cap-drop=ALL',
                      '--entrypoint', 'php', images['web'][0], '-r',
                      'echo PHP_VERSION," ",phpversion("redis");')
        assert re.fullmatch(r'8\.3\.(?:3[6-9]|[4-9]\d|\d{3,}) 6\.1\.0', php), 'Unreviewed PHP/PECL baseline'
        redis = command('docker', 'run', '--rm', '--network=none', '--read-only', '--cap-drop=ALL',
                        '--entrypoint', 'redis-server', images['redis'][0], '--version')
        version = re.search(r'v=7\.4\.(\d+)', redis)
        assert version and int(version[1]) >= 11, 'Unreviewed Redis baseline'
        edge = command('docker', 'run', '--rm', '--network=none', '--read-only', '--cap-drop=ALL',
                       '--entrypoint', 'haproxy', images['edge'][0], '-v')
        version = re.search(r'version 3\.2\.(\d+)', edge)
        assert version and int(version[1]) >= 26, 'Unreviewed HAProxy baseline'
        result = {'checkedAt': datetime.now(timezone.utc).isoformat(), 'database': database,
                  'images': summaries, 'blocked': blocked,
                  'manualRuntimeBaselines': {'phpAndExtension': php, 'redis': '7.4.>=11', 'haproxy': '3.2.>=26'},
                  'scope': 'OS/library inventory plus reviewed runtime floors, not all vulnerabilities or full manual-component SBOM'}
        (output / 'summary.json').write_text(json.dumps(result, indent=2) + '\n')
    assert not blocked, 'Image gate blocked; sanitized summary saved (no ignore-unfixed)'
    print('PASS: fresh exact-image inventories and reviewed runtime floors; no blocking records in this scope')


if __name__ == '__main__':
    main()
