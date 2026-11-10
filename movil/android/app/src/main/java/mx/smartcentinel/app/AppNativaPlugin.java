package mx.smartcentinel.app;

import android.content.Context;
import android.print.PrintAttributes;
import android.print.PrintManager;
import android.webkit.WebView;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

/**
 * Funciones nativas que la plataforma usa dentro de la app (public/js/pwa.js):
 *  - imprimir(): window.print() no hace nada en Android; aquí se abre el diálogo de impresión del sistema
 *    (impresoras de la red o «Guardar como PDF»).
 *  - reintentar(): desde la pantalla «Sin conexión» (www/sin-conexion.html) vuelve a cargar la plataforma.
 * Solo la ve el propio dominio de la plataforma (Capacitor no la entrega a otros sitios).
 */
@CapacitorPlugin(name = "AppNativa")
public class AppNativaPlugin extends Plugin {

    @PluginMethod
    public void imprimir(PluginCall call) {
        getActivity().runOnUiThread(() -> {
            WebView vista = getBridge().getWebView();
            String titulo = vista.getTitle() != null && !vista.getTitle().isEmpty() ? vista.getTitle() : getActivity().getString(R.string.app_name);
            PrintManager impresion = (PrintManager) getContext().getSystemService(Context.PRINT_SERVICE);
            impresion.print(titulo, vista.createPrintDocumentAdapter(titulo), new PrintAttributes.Builder().build());
            call.resolve();
        });
    }

    @PluginMethod
    public void reintentar(PluginCall call) {
        String destino = getBridge().getServerUrl() != null ? getBridge().getServerUrl() : getBridge().getAppUrl();
        getActivity().runOnUiThread(() -> {
            getBridge().getWebView().loadUrl(destino);
            call.resolve();
        });
    }
}
