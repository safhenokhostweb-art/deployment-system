@extends('layouts.app')
@section('title', 'Queue reconciliation')
@section('content')
<x-card title="Queue reconciliation">
<p>Only stale records older than {{ \App\Services\Operations\QueueReconciliationService::STALE_MINUTES }} minutes can be reconciled. Terminal history is retained.</p>
@foreach ([['deployment',$deployments],['publish',$publishes],['agent_control',$controls]] as [$type,$records])
<h3>{{ ucfirst(str_replace('_',' ',$type)) }}</h3>
<table><thead><tr><th>ID</th><th>Project / Agent</th><th>State</th><th>Age</th><th>Action</th></tr></thead><tbody>
@forelse($records as $record)<tr><td>{{ $record->id }}</td><td>{{ $record->project->project_key ?? $record->localAgent->name ?? '—' }}</td><td>{{ $record->status }}</td><td>{{ $record->updated_at?->diffForHumans() }}</td><td><form method="POST" action="{{ route('operations.queue-reconciliation.reconcile') }}">@csrf<input type="hidden" name="record_type" value="{{ $type }}"><input type="hidden" name="record_id" value="{{ $record->id }}"><input name="reason" required maxlength="1000" placeholder="Reason"><button class="btn btn-danger" type="submit">Reconcile</button></form></td></tr>@empty<tr><td colspan="5">No non-terminal records.</td></tr>@endforelse
</tbody></table>
@endforeach
</x-card>
@endsection
