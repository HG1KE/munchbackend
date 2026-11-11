@extends('layouts.admin.app')

@section('title', translate('Edit stock item'))

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-4">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <i class="tio-edit"></i>
                <span class="page-header-title">{{ translate('Edit stock item') }}</span>
            </h2>
        </div>

        <div class="card">
            <div class="card-body">
                <form action="{{ route('admin.stock-item.update', [$stockItem->id]) }}" method="post">
                    @csrf
                    <div class="form-group">
                        <label class="input-label">{{ translate('name') }}</label>
                        <input type="text" name="name" class="form-control" required value="{{ $stockItem->name }}">
                    </div>
                    <div class="form-group">
                        <label class="toggle-switch d-flex align-items-center">
                            <input type="checkbox" name="status" class="toggle-switch-input" {{ $stockItem->status ? 'checked' : '' }}>
                            <span class="toggle-switch-label"><span class="toggle-switch-indicator"></span></span>
                            <span class="toggle-switch-content ml-2">{{ translate('Active') }}</span>
                        </label>
                    </div>
                    <div class="d-flex justify-content-end gap-2">
                        <a href="{{ route('admin.stock-item.index') }}" class="btn btn-secondary">{{ translate('Cancel') }}</a>
                        <button type="submit" class="btn btn-primary">{{ translate('Update') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
