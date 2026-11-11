@extends('layouts.admin.app')

@section('title', translate('Stock managers'))

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-4">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <i class="tio-user"></i>
                <span class="page-header-title">{{ translate('Stock managers') }}</span>
            </h2>
        </div>

        <div class="card">
            <div class="card-top px-card pt-4">
                <div class="d-flex flex-column flex-md-row flex-wrap gap-3 justify-content-md-between align-items-md-center">
                    <h5 class="mb-0">{{ translate('Managers') }}
                        <span class="badge badge-soft-dark rounded-50 fz-12">{{ $managers->total() }}</span>
                    </h5>
                    <div class="d-flex flex-wrap gap-3">
                        <form action="{{ url()->current() }}" method="GET">
                            <div class="input-group">
                                <input type="search" name="search" class="form-control"
                                       placeholder="{{ translate('Search by name or email') }}"
                                       value="{{ $search }}" autocomplete="off">
                                <div class="input-group-append">
                                    <button type="submit" class="btn btn-primary">{{ translate('Search') }}</button>
                                </div>
                            </div>
                        </form>
                        <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#addStockManagerModal">
                            <i class="tio-add"></i> {{ translate('Add manager') }}
                        </button>
                    </div>
                </div>
            </div>

            <div class="py-4">
                <div class="table-responsive">
                    <table class="table table-borderless table-thead-bordered table-nowrap table-align-middle card-table">
                        <thead class="thead-light">
                        <tr>
                            <th>{{ translate('SL') }}</th>
                            <th>{{ translate('name') }}</th>
                            <th>{{ translate('email') }}</th>
                            <th>{{ translate('branch') }}</th>
                            <th class="text-center">{{ translate('status') }}</th>
                            <th class="text-center">{{ translate('action') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($managers as $key => $manager)
                            <tr>
                                <td>{{ $managers->firstItem() + $key }}</td>
                                <td>{{ $manager->name }}</td>
                                <td>{{ $manager->email }}</td>
                                <td>{{ $manager->branch?->name ?? '—' }}</td>
                                <td class="text-center">
                                    <label class="switcher mx-auto">
                                        <input type="checkbox" class="switcher_input redirect-url"
                                               {{ $manager->status ? 'checked' : '' }}
                                               data-url="{{ route('admin.stock-manager.status', [$manager->id, $manager->status ? 0 : 1]) }}">
                                        <span class="switcher_control"></span>
                                    </label>
                                </td>
                                <td>
                                    <div class="d-flex justify-content-center gap-2">
                                        <a class="btn btn-outline-info btn-sm" href="{{ route('admin.stock-manager.edit', [$manager->id]) }}">
                                            <i class="tio-edit"></i>
                                        </a>
                                        <button class="btn btn-outline-danger btn-sm" type="button"
                                                onclick="form_alert('stock-manager-{{ $manager->id }}','{{ translate('Want to delete this stock manager') }} ?')">
                                            <i class="tio-delete"></i>
                                        </button>
                                    </div>
                                    <form action="{{ route('admin.stock-manager.delete', [$manager->id]) }}"
                                          method="post" id="stock-manager-{{ $manager->id }}">
                                        @csrf @method('delete')
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted">{{ translate('No stock managers yet') }}</td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="table-responsive mt-4 px-3">
                    <div class="d-flex justify-content-lg-end">{!! $managers->links() !!}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="addStockManagerModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-body">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
                    <form action="{{ route('admin.stock-manager.store') }}" method="post">
                        @csrf
                        <div class="form-group">
                            <label class="input-label">{{ translate('name') }}</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="input-label">{{ translate('email') }}</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="input-label">{{ translate('password') }}</label>
                            <input type="password" name="password" class="form-control" required minlength="6">
                        </div>
                        <div class="form-group">
                            <label class="input-label">{{ translate('branch') }}</label>
                            <select name="branch_id" class="form-control" required>
                                <option value="">{{ translate('Select branch') }}</option>
                                @foreach($branches as $branch)
                                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary">{{ translate('submit') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
