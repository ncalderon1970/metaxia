<?php
require_once __DIR__ . '/../config/ollama.php';

class OllamaService {
    private $baseUrl;
    
    public function __construct() {
        $this->baseUrl = OllamaConfig::BASE_URL;
    }
    
    /**
     * Verifica si Ollama está corriendo
     */
    public function verificarConexion() {
        try {
            $ch = curl_init($this->baseUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            return $httpCode === 200;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Envía un prompt a Ollama y devuelve la respuesta
     */
    public function consultar($prompt, $modelo = null, $formato = null) {
        if ($modelo === null) {
            $modelo = OllamaConfig::MODEL_SMALL;
        }
        
        $url = $this->baseUrl . OllamaConfig::API_ENDPOINT;
        
        $data = [
            'model' => $modelo,
            'prompt' => $prompt,
            'stream' => false
        ];
        
        // Si se especifica formato JSON
        if ($formato === 'json') {
            $data['format'] = 'json';
        }
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, OllamaConfig::TIMEOUT);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception("Error de conexión: $error");
        }
        
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("Error HTTP: $httpCode");
        }
        
        $result = json_decode($response, true);
        
        if (!isset($result['response'])) {
            throw new Exception("Respuesta inválida de Ollama");
        }
        
        return $result['response'];
    }
    
    /**
     * Analiza un incidente de convivencia
     */
    public function analizarIncidente($descripcion, $contexto = '') {
        $prompt = "Analiza el siguiente incidente de convivencia escolar:

Descripción: $descripcion
" . ($contexto ? "Contexto adicional: $contexto\n" : "") . "

Proporciona un análisis en formato JSON con esta estructura:
{
    \"gravedad\": \"leve/grave/gravísimo\",
    \"categoria\": \"tipo de incidente\",
    \"protocolo_sugerido\": \"descripción del protocolo\",
    \"medidas_formativas\": [\"medida 1\", \"medida 2\", \"medida 3\"],
    \"observaciones\": \"comentarios adicionales\"
}

Responde SOLO con el JSON, sin texto adicional.";

        $respuesta = $this->consultar($prompt, OllamaConfig::MODEL_SMALL, 'json');
        
        try {
            return json_decode($respuesta, true);
        } catch (Exception $e) {
            // Si falla el JSON, devolver la respuesta cruda
            return ['respuesta_cruda' => $respuesta];
        }
    }
    
    /**
     * Genera una carta para apoderados
     */
    public function generarCarta($tipo, $datos) {
        $prompt = "Redacta una $tipo formal para apoderados de un colegio chileno.

Datos del estudiante: {$datos['estudiante']}
Curso: {$datos['curso']}
Motivo: {$datos['motivo']}
Fecha del incidente: {$datos['fecha']}

La carta debe:
- Ser formal pero empática
- Seguir protocolos escolares chilenos
- Incluir saludo, cuerpo y despedida
- Ser clara y respetuosa
- Invitar al diálogo y colaboración

Redacta la carta completa:";

        return $this->consultar($prompt, OllamaConfig::MODEL_LARGE);
    }
    
    /**
     * Sugiere medidas formativas
     */
    public function sugerirMedidasFormativas($incidente, $edad_estudiante) {
        $prompt = "Para un estudiante de $edad_estudiante años que tuvo el siguiente incidente:

$incidente

Sugiere 3 medidas formativas específicas basadas en:
- Política Nacional de Convivencia Escolar (Chile)
- Enfoque restaurativo
- Desarrollo socioemocional apropiado para la edad

Formato de respuesta:
1. [Medida] - Fundamentación pedagógica
2. [Medida] - Fundamentación pedagógica
3. [Medida] - Fundamentación pedagógica";

        return $this->consultar($prompt, OllamaConfig::MODEL_SMALL);
    }
    
    /**
     * Lista modelos disponibles
     */
    public function listarModelos() {
        $url = $this->baseUrl . '/api/tags';
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        return json_decode($response, true);
    }
}
?>