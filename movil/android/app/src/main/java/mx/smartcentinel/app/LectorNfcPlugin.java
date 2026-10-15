package mx.smartcentinel.app;

import android.app.Activity;
import android.content.ActivityNotFoundException;
import android.content.Intent;
import android.net.Uri;
import android.nfc.NdefMessage;
import android.nfc.NdefRecord;
import android.nfc.NfcAdapter;
import android.nfc.Tag;
import android.nfc.tech.Ndef;
import android.provider.Settings;
import android.util.Base64;
import android.util.Log;
import com.getcapacitor.JSArray;
import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;
import java.io.IOException;
import java.nio.charset.Charset;
import java.nio.charset.StandardCharsets;
import java.util.Arrays;
import java.util.Locale;

/**
 * NFC del teléfono dentro de la app (la web lo usa desde public/js/nfc-app.js como si fuera Web NFC).
 *  - Mientras la app está en primer plano, el lector NFC queda en «modo lector» (enableReaderMode):
 *    toda etiqueta que se acerque llega aquí y NINGUNA abre otra app ni el navegador.
 *  - Cada lectura se avisa a la página con el evento «lectura»: serialNumber (número de serie en hex con «:»,
 *    igual que Web NFC) y los registros NDEF (tipo, mediaType, datos en base64 y el texto ya decodificado).
 *  - disponible() → { nfc, encendido } y abrirAjustesNfc() para pedir que se encienda.
 * Ver docs/tecnico/app-movil.md («NFC en la app»).
 */
@CapacitorPlugin(name = "LectorNfc")
public class LectorNfcPlugin extends Plugin implements NfcAdapter.ReaderCallback {

    private static final String ETIQUETA_LOG = "LectorNfc";

    // Todas las tecnologías comunes de tarjetas y etiquetas; sin FLAG_READER_SKIP_NDEF_CHECK para leer el mensaje NDEF
    private static final int BANDERAS =
        NfcAdapter.FLAG_READER_NFC_A | NfcAdapter.FLAG_READER_NFC_B | NfcAdapter.FLAG_READER_NFC_F | NfcAdapter.FLAG_READER_NFC_V;

    private NfcAdapter adaptador;

    @Override
    public void load() {
        adaptador = NfcAdapter.getDefaultAdapter(getContext());
    }

    @Override
    protected void handleOnResume() {
        super.handleOnResume();
        activarLector();
    }

    @Override
    protected void handleOnPause() {
        super.handleOnPause();
        desactivarLector();
    }

    @PluginMethod
    public void disponible(PluginCall call) {
        NfcAdapter nfc = adaptador != null ? adaptador : NfcAdapter.getDefaultAdapter(getContext());
        JSObject respuesta = new JSObject();
        respuesta.put("nfc", nfc != null);
        respuesta.put("encendido", nfc != null && nfc.isEnabled());
        call.resolve(respuesta);
    }

    @PluginMethod
    public void abrirAjustesNfc(PluginCall call) {
        final Activity actividad = getActivity();
        actividad.runOnUiThread(() -> {
            try {
                actividad.startActivity(new Intent(Settings.ACTION_NFC_SETTINGS));
                call.resolve();
            } catch (ActivityNotFoundException e) {
                try {
                    actividad.startActivity(new Intent(Settings.ACTION_WIRELESS_SETTINGS));
                    call.resolve();
                } catch (ActivityNotFoundException e2) {
                    call.reject("No se pudieron abrir los ajustes del teléfono.");
                }
            }
        });
    }

    private void activarLector() {
        Activity actividad = getActivity();
        if (adaptador == null || actividad == null) {
            return;
        }
        try {
            adaptador.enableReaderMode(actividad, this, BANDERAS, null);
        } catch (RuntimeException e) {
            Log.w(ETIQUETA_LOG, "No se pudo activar el modo lector", e);
        }
    }

    private void desactivarLector() {
        Activity actividad = getActivity();
        if (adaptador == null || actividad == null) {
            return;
        }
        try {
            adaptador.disableReaderMode(actividad);
        } catch (RuntimeException e) {
            Log.w(ETIQUETA_LOG, "No se pudo desactivar el modo lector", e);
        }
    }

    /** Llega en un hilo del sistema (no en el de la pantalla). */
    @Override
    public void onTagDiscovered(Tag etiqueta) {
        if (etiqueta == null) {
            return;
        }
        JSObject lectura = new JSObject();
        lectura.put("serialNumber", serie(etiqueta.getId()));
        JSArray registros = new JSArray();
        try {
            NdefMessage mensaje = leerMensaje(etiqueta);
            if (mensaje != null) {
                for (NdefRecord r : mensaje.getRecords()) {
                    registros.put(registro(r));
                }
            }
        } catch (Exception e) {
            Log.w(ETIQUETA_LOG, "No se pudo leer la etiqueta", e);
            JSObject error = new JSObject();
            error.put("serialNumber", serie(etiqueta.getId()));
            error.put("mensaje", "No se pudo leer la etiqueta.");
            emitir("lecturaError", error);
            return;
        }
        lectura.put("records", registros);
        emitir("lectura", lectura);
    }

    private NdefMessage leerMensaje(Tag etiqueta) throws Exception {
        Ndef ndef = Ndef.get(etiqueta);
        if (ndef == null) {
            // Tarjeta sin formato NDEF (p. ej. un gafete): solo se usa su número de serie
            return null;
        }
        NdefMessage guardado = ndef.getCachedNdefMessage();
        if (guardado != null) {
            return guardado;
        }
        try {
            ndef.connect();
            return ndef.getNdefMessage();
        } finally {
            try {
                ndef.close();
            } catch (IOException e) {
                // Ya no está cerca: no importa
            }
        }
    }

    /** Un registro NDEF con los mismos nombres de Web NFC (recordType, mediaType, encoding, lang). */
    private JSObject registro(NdefRecord r) {
        int tnf = r.getTnf();
        byte[] tipo = r.getType() != null ? r.getType() : new byte[0];
        byte[] carga = r.getPayload() != null ? r.getPayload() : new byte[0];

        String recordType = "unknown";
        String mediaType = null;
        String encoding = null;
        String lang = null;
        String texto = null;
        byte[] datos = carga;

        if (tnf == NdefRecord.TNF_EMPTY) {
            recordType = "empty";
            datos = new byte[0];
        } else if (tnf == NdefRecord.TNF_WELL_KNOWN && Arrays.equals(tipo, NdefRecord.RTD_TEXT)) {
            recordType = "text";
            if (carga.length > 0) {
                int estado = carga[0] & 0xff;
                boolean utf16 = (estado & 0x80) != 0;
                int largoIdioma = estado & 0x3f;
                if (1 + largoIdioma <= carga.length) {
                    lang = new String(carga, 1, largoIdioma, StandardCharsets.US_ASCII);
                    Charset juego = utf16 ? StandardCharsets.UTF_16 : StandardCharsets.UTF_8;
                    texto = new String(carga, 1 + largoIdioma, carga.length - 1 - largoIdioma, juego);
                }
            }
            if (texto == null) {
                texto = "";
            }
            // Se entrega siempre en UTF-8 (el texto ya decodificado, sin el byte de estado ni el idioma)
            encoding = "utf-8";
            datos = texto.getBytes(StandardCharsets.UTF_8);
        } else if (tnf == NdefRecord.TNF_WELL_KNOWN && Arrays.equals(tipo, NdefRecord.RTD_URI)) {
            recordType = "url";
            texto = direccion(r);
            datos = texto != null ? texto.getBytes(StandardCharsets.UTF_8) : carga;
        } else if (tnf == NdefRecord.TNF_WELL_KNOWN && Arrays.equals(tipo, NdefRecord.RTD_SMART_POSTER)) {
            recordType = "smart-poster";
            texto = direccion(r);
        } else if (tnf == NdefRecord.TNF_ABSOLUTE_URI) {
            recordType = "absolute-url";
            texto = direccion(r);
            if (texto == null) {
                texto = new String(tipo, StandardCharsets.UTF_8);
            }
            datos = texto.getBytes(StandardCharsets.UTF_8);
        } else if (tnf == NdefRecord.TNF_MIME_MEDIA) {
            recordType = "mime";
            mediaType = new String(tipo, StandardCharsets.US_ASCII);
        } else if (tnf == NdefRecord.TNF_EXTERNAL_TYPE || tnf == NdefRecord.TNF_WELL_KNOWN) {
            recordType = new String(tipo, StandardCharsets.US_ASCII);
        }

        JSObject resultado = new JSObject();
        resultado.put("recordType", recordType);
        resultado.put("mediaType", mediaType);
        resultado.put("encoding", encoding);
        resultado.put("lang", lang);
        resultado.put("texto", texto);
        resultado.put("datos", Base64.encodeToString(datos, Base64.NO_WRAP));
        return resultado;
    }

    /** La dirección completa del registro (con su prefijo «https://…» ya expandido), o null. */
    private String direccion(NdefRecord registro) {
        try {
            Uri uri = registro.toUri();
            return uri != null ? uri.toString() : null;
        } catch (RuntimeException e) {
            return null;
        }
    }

    /** Número de serie como lo da Web NFC: hex en minúsculas separado por «:». */
    private String serie(byte[] id) {
        if (id == null) {
            return "";
        }
        StringBuilder texto = new StringBuilder();
        for (int i = 0; i < id.length; i++) {
            if (i > 0) {
                texto.append(':');
            }
            texto.append(String.format(Locale.ROOT, "%02x", id[i] & 0xff));
        }
        return texto.toString();
    }

    /** Los avisos a la página salen desde el hilo de la pantalla. */
    private void emitir(final String evento, final JSObject datos) {
        Activity actividad = getActivity();
        if (actividad == null) {
            return;
        }
        actividad.runOnUiThread(() -> notifyListeners(evento, datos));
    }
}
