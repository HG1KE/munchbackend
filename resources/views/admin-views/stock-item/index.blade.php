@extends('layouts.admin.app')

@section('title', translate('Stocks'))

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-4">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <i class="tio-cube"></i>
                <span class="page-header-title">{{ translate('Stocks') }}</span>
            </h2>
        </div>

        <div class="card">
            <div class="card-top px-card pt-4">
                <div class="d-flex flex-column flex-md-row flex-wrap gap-3 justify-content-md-between align-items-md-center">
                    <h5 class="d-flex align-items-center gap-2 mb-0">
                        {{ translate('Stock items') }}
                        <span class="badge badge-soft-dark rounded-50 fz-12">{{ $stockItems->total() }}</span>
                    </h5>
                    <div class="d-flex flex-wrap justify-content-md-end gap-3">
                        <form action="{{ url()->current() }}" method="GET">
                            <div class="input-group">
                                <input type="search" name="search" class="form-control"
                                       placeholder="{{ translate('Search by name') }}"
                                       value="{{ $search }}" autocomplete="off">
                                <div class="input-group-append">
                                    <button type="submit" class="btn btn-primary">{{ translate('Search') }}</button>
                                </div>
                            </div>
                        </form>
                        <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#addStockItemModal">
                            <i class="tio-add"></i> {{ translate('Add stock item') }}
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
                            <th class="text-center">{{ translate('status') }}</th>
                            <th class="text-center">{{ translate('action') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($stockItems as $key => $item)
                            <tr>
                                <td>{{ $stockItems->firstItem() + $key }}</td>
                                <td>{{ $item->name }}</td>
                                <td class="text-center">
                                    <label class="switcher mx-auto">
                                        <input type="checkbox" class="switcher_input redirect-url"
                                               {{ $item->status ? 'checked' : '' }}
                                               data-url="{{ route('admin.stock-item.status', [$item->id, $item->status ? 0 : 1]) }}">
                                        <span class="switcher_control"></span>
                                    </label>
                                </td>
                                <td>
                                    <div class="d-flex justify-content-center gap-2">
                                        <a class="btn btn-outline-info btn-sm edit square-btn"
                                           href="{{ route('admin.stock-item.edit', [$item->id]) }}"><i class="tio-edit"></i></a>
                                        <button class="btn btn-outline-danger btn-sm delete square-btn" type="button"
                                                onclick="form_alert('stock-item-{{ $item->id }}','{{ translate('Want to delete this stock item') }} ?')">
                                            <i class="tio-delete"></i>
                                        </button>
                                    </div>
                                    <form action="{{ route('admin.stock-item.delete', [$item->id]) }}"
                                          method="post" id="stock-item-{{ $item->id }}">
                                        @csrf @method('delete')
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center text-muted">{{ translate('No stock items yet') }}</td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="table-responsive mt-4 px-3">
                    <div class="d-flex justify-content-lg-end">
                        {!! $stockItems->links() !!}
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="addStockItemModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-body">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                    <form action="{{ route('admin.stock-item.store') }}" method="post">
                        @csrf
                        <div class="form-group">
                            <label class="input-label">{{ translate('name') }}</label>
                            <input type="text" name="name" class="form-control" required
                                   placeholder="{{ translate('Ex : Beef Patties') }}">
                        </div>
                        <div class="form-group">
                            <label class="toggle-switch d-flex align-items-center">
                                <input type="checkbox" name="status" class="toggle-switch-input" checked>
                                <span class="toggle-switch-label"><span class="toggle-switch-indicator"></span></span>
                                <span class="toggle-switch-content ml-2">{{ translate('Active') }}</span>
                            </label>
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
