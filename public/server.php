<?php
session_start();
$logFile = './logs/game_log.log';
// Coses dites a classe:
// fer una interpola·lació pel jugador i que no es veguin els ticks

function logMessage($message)
{
    global $logFile;
    // Escribir en el log el mensaje con una marca de tiempo
    error_log(date('[Y-m-d H:i:s] ') . $message . PHP_EOL, 3, $logFile);
}


// Conectar a la base de datos SQLite
try {
    $db = new PDO('sqlite:../private/games.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['error' => 'Conexión con la base de datos fallida: ' . $e->getMessage()]);
    exit();
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

// Manejar las acciones del juego
switch ($action) {
    case 'join':
        if (!isset($_SESSION['player_id'])) {
            $_SESSION['player_id'] = uniqid();
        }

        $player_id = $_SESSION['player_id'];
        $game_id = null;

        // Intentar unirse a un juego existente
        $stmt = $db->prepare('SELECT game_id FROM games WHERE player2 IS NULL LIMIT 1');
        $stmt->execute();
        $game = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($game) {
            // Unirse como player2
            $game_id = $game['game_id'];
            $stmt = $db->prepare('UPDATE games SET player2 = :player_id WHERE game_id = :game_id');
            $stmt->bindValue(':player_id', $player_id);
            $stmt->bindValue(':game_id', $game_id);
            $stmt->execute();
        } else {
            // Crear un nuevo juego como player1
            $game_id = uniqid();
            $stmt = $db->prepare('INSERT INTO games (game_id, player1) VALUES (:game_id, :player_id)');
            $stmt->bindValue(':game_id', $game_id);
            $stmt->bindValue(':player_id', $player_id);
            $stmt->execute();

            // Generar un cuadrado amarillo en una posición aleatoria
            $squareX = rand(100, 700);  // Posición aleatoria X
            $squareY = rand(100, 500);  // Posición aleatoria Y
            logMessage("Posicion Cuadrado: " . $squareX . " " . "$squareY");
            $stmt = $db->prepare('INSERT INTO squares (game_id, x, y) VALUES (:game_id, :x, :y)');
            $stmt->bindValue(':game_id', $game_id);
            $stmt->bindValue(':x', $squareX);
            $stmt->bindValue(':y', $squareY);
            $stmt->execute();
        }

        echo json_encode(['gameId' => $game_id, 'playerId' => $player_id]);
        break;

    case 'update':
        $data = json_decode(file_get_contents('php://input'), true);
        $game_id = $data['gameId'];
        $player_id = $data['playerId'];

        // Convertir las balas en JSON para almacenarlas
        $bullets = json_encode($data['bullets']);

        // Actualizar el estado del jugador en la base de datos
        $stmt = $db->prepare('INSERT OR REPLACE INTO players (game_id, player_id, x, y, angle, bullets) 
                            VALUES (:game_id, :player_id, :x, :y, :angle, :bullets)');
        $stmt->bindValue(':game_id', $game_id);
        $stmt->bindValue(':player_id', $player_id);
        $stmt->bindValue(':x', $data['x']);
        $stmt->bindValue(':y', $data['y']);
        $stmt->bindValue(':angle', $data['angle']);
        $stmt->bindValue(':bullets', $bullets);
        $stmt->execute();
        break;

    case 'shoot':
        $data = json_decode(file_get_contents('php://input'), true);
        $game_id = $data['gameId'];
        $x = $data['x'];
        $y = $data['y'];
        $direction = $data['direction'];

        // Crear un id único para la bala
        $bullet_id = uniqid();

        // Insertar la bala en la base de datos
        $stmt = $db->prepare('INSERT INTO bullets (bullet_id, game_id, x, y, direction, created_at) VALUES (:bullet_id, :game_id, :x, :y, :direction, CURRENT_TIMESTAMP)');
        $stmt->bindValue(':bullet_id', $bullet_id);
        $stmt->bindValue(':game_id', $game_id);
        $stmt->bindValue(':x', $x);
        $stmt->bindValue(':y', $y);
        $stmt->bindValue(':direction', $direction);
        $stmt->execute();
        break;

    case 'cleanBullets':
        $stmt = $db->prepare('DELETE FROM bullets WHERE (strftime("%s", "now") - strftime("%s", created_at)) > 5');
        $stmt->execute();
        break;

    case 'getBullets':
        $game_id = $_GET['gameId'];

        // Obtener las balas del juego
        $stmt = $db->prepare('SELECT bullet_id, x, y, direction, created_at FROM bullets WHERE game_id = :game_id');
        $stmt->bindValue(':game_id', $game_id);
        $stmt->execute();
        $bullets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Obtener la posición del cuadrado
        $stmt = $db->prepare('SELECT x, y, is_visible FROM squares WHERE game_id = :game_id');
        $stmt->bindValue(':game_id', $game_id);
        $stmt->execute();
        $square = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($square && $square['is_visible'] == 1) {
            $squareX = $square['x'];
            $squareY = $square['y'];

            $initialBulletSpeed = 140; // Velocidad inicial de la bala

            foreach ($bullets as $bullet) {
                // Calcular el tiempo transcurrido desde la creación de la bala
                $bulletCreationTime = new DateTime($bullet['created_at']);
                $currentTime = new DateTime();

                $bulletCreationTimeInMilliseconds = (float) $bulletCreationTime->format('U.u');
                $currentTimeInMilliseconds = microtime(true);
                $timeElapsed = $currentTimeInMilliseconds - $bulletCreationTimeInMilliseconds;

                // Aumentar la velocidad de la bala con el tiempo
                $accelerationFactor = 1 + ($timeElapsed * 0.3); // Ajusta este valor para controlar la aceleración
                $bulletSpeed = $initialBulletSpeed * $accelerationFactor;

                // Calcular la nueva posición de la bala    
                $newBulletX = $bullet['x'] + $bulletSpeed * $timeElapsed * cos($bullet['direction']);
                $newBulletY = $bullet['y'] + $bulletSpeed * $timeElapsed * sin($bullet['direction']);

                logMessage("Posicion Bala: " . $newBulletX . " " . "$newBulletY" . " " . $timeElapsed . " Velocidad: " . $bulletSpeed);

                // Verificar si la bala impacta el cuadrado
                if (sqrt(pow($newBulletX - $squareX, 2) + pow($newBulletY - $squareY, 2)) < 25) {
                    // Desactivar el cuadrado si hay colisión
                    logMessage("Colision");
                    $stmt = $db->prepare('UPDATE squares SET is_visible = 0 WHERE game_id = :game_id');
                    $stmt->bindValue(':game_id', $game_id);
                    $stmt->execute();

                    // Crear un nuevo cuadrado en una posición aleatoria
                    $newSquareX = rand(100, 700);
                    $newSquareY = rand(100, 500);
                    $stmt = $db->prepare('UPDATE squares SET x = :x, y = :y, is_visible = 1 WHERE game_id = :game_id');
                    $stmt->bindValue(':x', $newSquareX);
                    $stmt->bindValue(':y', $newSquareY);
                    $stmt->bindValue(':game_id', $game_id);
                    $stmt->execute();

                    $stmt = $db->prepare('DELETE FROM bullets WHERE bullet_id = :bullet_id');
                    $stmt->bindValue(':bullet_id', $bullet['bullet_id']);
                    $stmt->execute();

                    break;  // Solo una colisión por ciclo
                }
            }
        }

        // Enviar las balas actualizadas al cliente
        echo json_encode(['bullets' => $bullets]);
        break;

    case 'status':
        $game_id = $_GET['gameId'];

        // Obtener el estado de todos los jugadores en este juego
        $stmt = $db->prepare('SELECT player_id, x, y, angle, bullets FROM players WHERE game_id = :game_id');
        $stmt->bindValue(':game_id', $game_id);
        $stmt->execute();
        $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Obtener la información del cuadrado
        $stmt = $db->prepare('SELECT x, y, is_visible FROM squares WHERE game_id = :game_id');
        $stmt->bindValue(':game_id', $game_id);
        $stmt->execute();
        $square = $stmt->fetch(PDO::FETCH_ASSOC);

        $otherPlayers = [];
        foreach ($players as $player) {
            $otherPlayers[$player['player_id']] = [
                'x' => $player['x'],
                'y' => $player['y'],
                'angle' => $player['angle'],
                'bullets' => json_decode($player['bullets'], true) // Decodificar balas almacenadas
            ];
        }

        echo json_encode(['otherPlayers' => $otherPlayers, 'square' => $square]);
        break;

    default:
        echo json_encode(['error' => 'Acción no válida']);
        break;
}