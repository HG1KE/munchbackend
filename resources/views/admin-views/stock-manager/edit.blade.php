@extends('layouts.admin.app')

@section('title', translate('Edit stock manager'))

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-4">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <i class="tio-edit"></i>
                <span class="page-header-title">{{ translate('Edit stock manager') }}</span>
            </h2>
        </div>

        <div class="card">
            <div class="card-body">
                <form action="{{ route('admin.stock-manager.update', [$manager->id]) }}" method="post" id="edit-stock-manager-form">
                    @csrf
                    <div class="form-group">
                        <label class="input-label">{{ translate('name') }}</label>
                        <input type="text" name="name" class="form-control" required value="{{ $manager->name }}">
                    </div>
                    <div class="form-group">
                        <label class="input-label">{{ translate('email') }}</label>
                        <input type="email" name="email" class="form-control" required value="{{ $manager->email }}">
                    </div>
                    <div class="form-group">
                        <label class="input-label">{{ translate('password') }} ({{ translate('leave blank to keep') }})</label>
                        <input type="password" name="password" class="form-control" minlength="6">
                    </div>
                    <div class="form-group">
                        <label class="toggle-switch d-flex align-items-center">
                            <input type="checkbox" name="all_branches" value="1" class="toggle-switch-input" id="edit-all-branches"
                                   {{ $manager->all_branches ? 'checked' : '' }}>
                            <span class="toggle-switch-label"><span class="toggle-switch-indicator"></span></span>
                            <span class="toggle-switch-content ml-2">{{ translate('All branches') }}</span>
                        </label>
                    </div>
                    <div class="form-group" id="edit-branch-ids-wrap">
                        <label class="input-label">{{ translate('branches') }}</label>
                        @php($selectedIds = $manager->branches->pluck('id')->map(function ($id) { return (int) $id; })->all())
                        <select name="branch_ids[]" id="edit-branch-ids" class="form-control" multiple="multiple">
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}" {{ in_array((int) $branch->id, $selectedIds, true) ? 'selected' : '' }}>
                                    {{ $branch->name }}
                                </option>
                            @endforeach
                        </select>
                        <small class="text-muted">{{ translate('Select one or more branches, or enable All branches.') }}</small>
                    </div>
                    <div class="form-group">
                        <label class="toggle-switch d-flex align-items-center">
                            <input type="checkbox" name="status" class="toggle-switch-input" {{ $manager->status ? 'checked' : '' }}>
                            <span class="toggle-switch-label"><span class="toggle-switch-indicator"></span></span>
                            <span class="toggle-switch-content ml-2">{{ translate('Active') }}</span>
                        </label>
                    </div>
                    <div class="d-flex justify-content-end gap-2">
                        <a href="{{ route('admin.stock-manager.index') }}" class="btn btn-secondary">{{ translate('Cancel') }}</a>
                        <button type="submit" class="btn btn-primary">{{ translate('Update') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('script_2')
    <script>
        "use strict";
        $(document).ready(function () {
            $("#edit-branch-ids").select2({
                placeholder: "{{ translate('Select branch') }}",
                allowClear: true,
                width: '100%'
            });

            function syncEditAllBranches() {
                var all = $('#edit-all-branches').is(':checked');
                $('#edit-branch-ids').prop('disabled', all);
                if (all) {
                    $('#edit-branch-ids').val(null).trigger('change');
                }
            }

            $('#edit-all-branches').on('change', syncEditAllBranches);
            syncEditAllBranches();
        });
    </script>
@endpush
