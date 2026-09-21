<?php

const CITA_TIMEZONE   = 'America/Bogota';
const CITA_HORAS_FIJAS = ['10:00', '15:00']; // 10:00 am y 3:00 pm

const DIAS_SEMANA_ES = [
    1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom',
];
const MESES_ES = [
    1 => 'ene', 2 => 'feb', 3 => 'mar', 4 => 'abr', 5 => 'may', 6 => 'jun',
    7 => 'jul', 8 => 'ago', 9 => 'sep', 10 => 'oct', 11 => 'nov', 12 => 'dic',
];

/**
 * Devuelve los próximos $cantidadDias días hábiles (lunes a viernes), empezando mañana.
 * @return DateTime[]
 */
function obtenerProximosDiasHabiles($cantidadDias = 5) {
    $tz = new DateTimeZone(CITA_TIMEZONE);
    $dias = [];
    $fecha = new DateTime('tomorrow', $tz);

    while (count($dias) < $cantidadDias) {
        $diaSemanaISO = (int) $fecha->format('N'); // 1 (lun) .. 7 (dom)
        if ($diaSemanaISO <= 5) {
            $dias[] = clone $fecha;
        }
        $fecha->modify('+1 day');
    }

    return $dias;
}

/**
 * Construye las secciones de la lista de WhatsApp con las franjas disponibles.
 * Formato de id de fila: slot_YYYY-MM-DD_HH:MM  (se parsea de vuelta en confirmarCitaCalendar()).
 */
function construirSeccionesFranjas($cantidadDias = 5) {
    $secciones = [];

    foreach (obtenerProximosDiasHabiles($cantidadDias) as $dia) {
        $diaSemana = DIAS_SEMANA_ES[(int) $dia->format('N')];
        $mes       = MESES_ES[(int) $dia->format('n')];
        $tituloSeccion = $diaSemana . ' ' . $dia->format('d') . ' ' . $mes; // ej: "Lun 22 sep" (10 chars, cabe en 24)

        $filas = [];
        foreach (CITA_HORAS_FIJAS as $hora) {
            [$h, $m] = explode(':', $hora);
            $horaLegible = ((int)$h > 12 ? (int)$h - 12 : (int)$h) . ':' . $m . ' ' . ((int)$h >= 12 ? 'pm' : 'am');
            $filas[] = [
                'id'          => 'slot_' . $dia->format('Y-m-d') . '_' . $hora,
                'title'       => $horaLegible,
                'description' => 'Llamada con un asesor EISO',
            ];
        }

        $secciones[] = [
            'title' => $tituloSeccion,
            'rows'  => $filas,
        ];
    }

    return $secciones;
}

/**
 * Parsea el id de fila seleccionado ("slot_2026-09-22_10:00") en un DateTime de inicio
 * y uno de fin (60 minutos de duración), ya en la zona horaria de la cita.
 * Usado por el flujo CONVERSACIONAL (botones/listas de WhatsApp vía lead_qualifier.php).
 * Devuelve null si el id no tiene el formato esperado o si la fecha ya no es un día hábil futuro.
 */
function parsearFranjaSeleccionada($rowId) {
    if (!preg_match('/^slot_(\d{4}-\d{2}-\d{2})_(\d{2}:\d{2})$/', $rowId, $m)) {
        return null;
    }

    $tz = new DateTimeZone(CITA_TIMEZONE);
    $inicio = DateTime::createFromFormat('Y-m-d H:i', $m[1] . ' ' . $m[2], $tz);
    if (!$inicio) {
        return null;
    }

    $diaSemanaISO = (int) $inicio->format('N');
    if ($diaSemanaISO > 5) {
        return null;
    }

    $fin = clone $inicio;
    $fin->modify('+60 minutes');

    return ['inicio' => $inicio, 'fin' => $fin];
}

const DIAS_SEMANA_LARGO_ES = [
    1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo',
];

/**
 * Formatea un DateTime como "Lunes 22 sep, 10:00 a.m." (usado en las etiquetas del Flow).
 */
function formatearFranjaLegible(DateTime $dt) {
    $diaSemana = DIAS_SEMANA_LARGO_ES[(int) $dt->format('N')];
    $mes       = MESES_ES[(int) $dt->format('n')];
    $h24       = (int) $dt->format('H');
    $h12       = $h24 % 12 === 0 ? 12 : $h24 % 12;
    $ampm      = $h24 >= 12 ? 'p.m.' : 'a.m.';
    return "{$diaSemana} {$dt->format('d')} {$mes}, {$h12}:{$dt->format('i')} {$ampm}";
}

/**
 * Construye la lista PLANA de franjas (para el campo "slots" del Flow nativo, distinto
 * del formato de secciones que usa el mensaje de lista de WhatsApp conversacional).
 * Cada elemento: ['id' => '2026-09-22T10:00:00-05:00', 'title' => 'Lunes 22 sep, 10:00 a.m.']
 */
function construirSlotsPlanoFlow($cantidadDias = 5) {
    $slots = [];
    foreach (obtenerProximosDiasHabiles($cantidadDias) as $dia) {
        foreach (CITA_HORAS_FIJAS as $hora) {
            [$h, $m] = explode(':', $hora);
            $dt = clone $dia;
            $dt->setTime((int) $h, (int) $m, 0);
            $slots[] = [
                'id'    => $dt->format('Y-m-d\TH:i:sP'), // ISO-8601 con offset, ej: 2026-09-22T10:00:00-05:00
                'title' => formatearFranjaLegible($dt),
            ];
        }
    }
    return $slots;
}

/**
 * Parsea el id ISO-8601 seleccionado en APPOINTMENT_SLOTS y devuelve inicio/fin (60 min).
 */
function parsearSlotFlow($isoId) {
    try {
        $inicio = new DateTime($isoId);
    } catch (Exception $e) {
        return null;
    }
    $diaSemanaISO = (int) $inicio->format('N');
    if ($diaSemanaISO > 5) {
        return null;
    }
    $fin = clone $inicio;
    $fin->modify('+60 minutes');
    return ['inicio' => $inicio, 'fin' => $fin];
}
