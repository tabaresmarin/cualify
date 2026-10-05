/* Panel Cualify — comportamiento de cliente.
 *
 * Los datos de los graficos los inyecta cada vista en window.CUALIFY_*,
 * asi que este archivo no hace fetch: solo dibuja. El endpoint /api/metrics
 * existe para refrescos programaticos, no para el render inicial.
 */

(function () {
    'use strict';

    var paleta = {
        azul: '#1570ef',
        verde: '#12b76a',
        morado: '#7f56d9',
        rojo: '#f04438',
        ambar: '#f79009',
        gris: '#98a2b3'
    };

    var datosDashboard = window.CUALIFY_DASHBOARD || null;
    var datosPageSpeed = window.CUALIFY_PAGESPEED || null;

    function existe(objeto) {
        return objeto !== null && objeto !== undefined;
    }

    // ---------------------------------------------------------------- graficos

    function crearCanvas(id) {
        var nodo = document.getElementById(id);
        if (!nodo || typeof Chart === 'undefined') {
            return null;
        }
        return nodo.getContext('2d');
    }

    function sinBorde(opciones) {
        return Object.assign({
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { padding: 10, cornerRadius: 8 }
            }
        }, opciones || {});
    }

    function dibujarLeadsPorDia(datos) {
        var ctx = crearCanvas('chartLeads');
        if (!ctx) {
            return;
        }

        var gradiente = ctx.createLinearGradient(0, 0, 0, 260);
        gradiente.addColorStop(0, 'rgba(21, 112, 239, 0.28)');
        gradiente.addColorStop(1, 'rgba(21, 112, 239, 0)');

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: datos.etiquetasDia || [],
                datasets: [{
                    label: 'Leads',
                    data: datos.datosDia || [],
                    borderColor: paleta.azul,
                    backgroundColor: gradiente,
                    borderWidth: 2,
                    fill: true,
                    tension: 0.32,
                    pointRadius: 0,
                    pointHoverRadius: 4
                }]
            },
            options: sinBorde({
                scales: {
                    y: {
                        beginAtZero: true,
                        precision: 0,
                        ticks: { color: '#667085' },
                        grid: { color: '#f0f2f5' }
                    },
                    x: {
                        ticks: { color: '#667085', maxRotation: 0, autoSkip: true, maxTicksLimit: 10 },
                        grid: { display: false }
                    }
                }
            })
        });
    }

    function dibujarEstado(datos) {
        var ctx = crearCanvas('chartStatus');
        if (!ctx) {
            return;
        }

        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: datos.etiquetasStatus || [],
                datasets: [{
                    data: datos.datosStatus || [],
                    backgroundColor: [paleta.gris, paleta.ambar, paleta.verde, paleta.rojo],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: sinBorde({
                cutout: '62%',
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom',
                        labels: { boxWidth: 10, color: '#475467', padding: 14 }
                    }
                }
            })
        });
    }

    function dibujarDistribucion(datos) {
        var ctx = crearCanvas('chartDistribucion');
        if (!ctx) {
            return;
        }

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: datos.etiquetas || [],
                datasets: [{
                    label: 'Leads',
                    data: datos.datos || [],
                    backgroundColor: datos.colores || [paleta.rojo, paleta.ambar, paleta.verde, paleta.gris],
                    borderRadius: 6,
                    maxBarThickness: 46
                }]
            },
            options: sinBorde({
                indexAxis: 'y',
                scales: {
                    x: {
                        beginAtZero: true,
                        precision: 0,
                        ticks: { color: '#667085' },
                        grid: { color: '#f0f2f5' }
                    },
                    y: {
                        ticks: { color: '#475467' },
                        grid: { display: false }
                    }
                }
            })
        });
    }

    if (existe(datosDashboard)) {
        dibujarLeadsPorDia(datosDashboard);
        dibujarEstado(datosDashboard);
    }

    if (existe(datosPageSpeed)) {
        dibujarDistribucion(datosPageSpeed);
    }

    // ---------------------------------------------------------------- datatables

    function activarTabla(id) {
        var tabla = document.getElementById(id);
        if (!tabla || typeof jQuery === 'undefined' || !$.fn.DataTable) {
            return;
        }

        $(tabla).DataTable({
            language: {
                url: 'https://cdn.datatables.net/1.13.8/i18n/es.json',
                search: 'Buscar:',
                lengthMenu: 'Mostrar _MENU_ registros',
                info: 'Mostrando _START_ a _END_ de _TOTAL_',
                infoEmpty: 'Sin registros',
                zeroRecords: 'Sin coincidencias',
                emptyTable: 'Sin datos',
                paginate: { first: 'Primero', last: 'Ultimo', next: 'Siguiente', previous: 'Anterior' }
            },
            order: [],
            pageLength: 25,
            lengthChange: false,
            dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>"
                + "<'row'<'col-sm-12'tr>>"
                + "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>"
        });
    }

    activarTabla('tablaLeads');

    // /conversations?tel=... deja el hilo a la vista. La pagina ya trae todos
    // los hilos, asi que esto solo desplaza hasta el que se pidio.
    var foco = window.CUALIFY_FOCO || '';
    if (foco) {
        var destino = document.getElementById('tel-' + foco);
        if (destino) {
            destino.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }
})();
