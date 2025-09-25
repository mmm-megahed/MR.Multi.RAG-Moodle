#!/bin/bash
set -e

ollama serve &
PID=$!

# Wait for server to start
for i in {1..20}; do
  if curl -s http://localhost:11434/api/version > /dev/null; then
    echo "Ollama is up!"
    break
  fi
  echo "Waiting for Ollama..."
  sleep 2
done

# Pull models
ollama pull gemma3:1b || true
ollama pull phi3:3.8b-mini-4k-instruct-q4_K_S || true
ollama pull mxbai-embed-large || true
ollama pull nomic-embed-text || true
# Keep Ollama running
wait $PID
