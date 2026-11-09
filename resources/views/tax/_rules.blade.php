@props(['versions' => []])
@if (count($versions))
<details class="tax-rules">
    <summary>Rules used ({{ count($versions) }})</summary>
    <x-fx.table caption="Rule versions used in this calculation">
        <thead><tr><th scope="col">Version</th><th scope="col">Rule</th><th scope="col">How sure</th><th scope="col">Legal status</th><th scope="col">Valid from</th><th scope="col">Source</th></tr></thead>
        <tbody>
        @foreach ($versions as $v)
            <tr>
                <td data-label="Version" class="is-lead">{{ $v['version'] }}</td>
                <td data-label="Rule">{{ $v['key'] }}</td>
                <td data-label="How sure"><x-fx.pill :kind="$v['confidence'] === 'high' ? 'plain' : 'variance'">{{ ucfirst($v['confidence']) }}</x-fx.pill></td>
                <td data-label="Legal status">{{ $v['legal_status'] }}</td>
                <td data-label="Valid from">{{ $v['valid_from'] }}</td>
                <td data-label="Source"><a class="fx-link" href="{{ $v['source_url'] }}" rel="noopener noreferrer">{{ \Illuminate\Support\Str::limit($v['source_url'], 48) }}</a></td>
            </tr>
        @endforeach
        </tbody>
    </x-fx.table>
</details>
@endif
