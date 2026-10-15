package mx.smartcentinel.app;

import android.app.DownloadManager;
import android.content.ActivityNotFoundException;
import android.content.Context;
import android.content.Intent;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.webkit.CookieManager;
import android.webkit.URLUtil;
import android.webkit.WebView;
import android.widget.Toast;
import androidx.activity.OnBackPressedCallback;
import androidx.appcompat.app.AlertDialog;
import com.getcapacitor.BridgeActivity;

/**
 * La app envuelve la plataforma web (dirección en movil/app.config.json).
 *  - «Atrás» de Android regresa en la página; en la primera pantalla pregunta antes de salir.
 *  - Descargas (PDF / Excel): se bajan con la sesión de la app a la carpeta Descargas.
 *  - La sesión se guarda en disco al salir de la app (no se pierde si Android la cierra).
 */
public class MainActivity extends BridgeActivity {

    @Override
    public void onCreate(Bundle savedInstanceState) {
        registerPlugin(AppNativaPlugin.class);
        super.onCreate(savedInstanceState);
        if (bridge == null) {
            return;
        }

        bridge.getWebView().setDownloadListener((url, agente, disposicion, tipo, tamano) -> descargar(url, agente, disposicion, tipo));

        getOnBackPressedDispatcher().addCallback(this, new OnBackPressedCallback(true) {
            @Override
            public void handleOnBackPressed() {
                WebView vista = bridge.getWebView();
                if (vista.canGoBack()) {
                    vista.goBack();
                } else {
                    preguntarSalir();
                }
            }
        });
    }

    @Override
    public void onPause() {
        super.onPause();
        // Guarda la cookie de sesión: el cierre por inactividad lo sigue decidiendo la plataforma
        CookieManager.getInstance().flush();
    }

    private void preguntarSalir() {
        new AlertDialog.Builder(this)
            .setTitle(R.string.salir_titulo)
            .setPositiveButton(R.string.salir_si, (dialogo, cual) -> finish())
            .setNegativeButton(R.string.salir_no, null)
            .show();
    }

    private void descargar(String url, String agente, String disposicion, String tipo) {
        Uri uri = Uri.parse(url);
        // Archivos creados en la propia página (blob:, data:) o equipos con Android 9 o anterior: navegador del sistema
        if (!"https".equalsIgnoreCase(uri.getScheme()) || Build.VERSION.SDK_INT < Build.VERSION_CODES.Q) {
            abrirEnNavegador(uri);
            return;
        }
        try {
            String nombre = URLUtil.guessFileName(url, disposicion, tipo);
            DownloadManager.Request solicitud = new DownloadManager.Request(uri);
            String cookies = CookieManager.getInstance().getCookie(url);
            if (cookies != null) {
                solicitud.addRequestHeader("Cookie", cookies);
            }
            solicitud.addRequestHeader("User-Agent", agente);
            solicitud.setMimeType(tipo);
            solicitud.setTitle(nombre);
            solicitud.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
            solicitud.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, nombre);
            DownloadManager descargas = (DownloadManager) getSystemService(Context.DOWNLOAD_SERVICE);
            descargas.enqueue(solicitud);
            Toast.makeText(this, getString(R.string.descargando, nombre), Toast.LENGTH_LONG).show();
        } catch (Exception e) {
            abrirEnNavegador(uri);
        }
    }

    private void abrirEnNavegador(Uri uri) {
        try {
            startActivity(new Intent(Intent.ACTION_VIEW, uri));
        } catch (ActivityNotFoundException e) {
            // Sin navegador instalado: no hay a dónde mandarlo
        }
    }
}
