/**
 * Munch admin/branch theme helpers (SweetAlert confirm color, chart accents).
 */
(function (global) {
    'use strict';

    var primary = '#E7032D';
    var primaryHover = '#C10226';
    var primaryRgb = '231, 3, 45';
    var chartFill = 'rgba(231, 3, 45, 0.5)';

    global.MunchAdminTheme = {
        primary: primary,
        primaryHover: primaryHover,
        primaryRgb: primaryRgb,
        chartPrimaryFill: chartFill,
        chartPrimarySolid: primary,
    };

    if (global.Swal && typeof global.Swal.fire === 'function') {
        var nativeFire = global.Swal.fire.bind(global.Swal);
        global.Swal.fire = function (options) {
            if (options && typeof options === 'object' && options.confirmButtonColor === undefined) {
                options = Object.assign({}, options, { confirmButtonColor: primary });
            }
            return nativeFire(options);
        };
    }
})(typeof window !== 'undefined' ? window : this);
