from ..LLMInterface import LLMInterface
from ..LLMEnums import DocumentTypeEnum
import requests
import logging
from typing import List, Union
import json

class OllamaProvider(LLMInterface):

    def __init__(self, api_url: str = "http://localhost:11434",
                       default_input_max_characters: int = 1000,
                       default_generation_max_output_tokens: int = 1000,
                       default_generation_temperature: float = 0.1):
        
        self.api_url = api_url.rstrip('/')
        
        self.default_input_max_characters = default_input_max_characters
        self.default_generation_max_output_tokens = default_generation_max_output_tokens
        self.default_generation_temperature = default_generation_temperature

        self.generation_model_id = None
        self.embedding_model_id = None
        self.embedding_size = None

        self.logger = logging.getLogger(__name__)

    def set_generation_model(self, model_id: str):
        self.generation_model_id = model_id

    def set_embedding_model(self, model_id: str, embedding_size: int):
        self.embedding_model_id = model_id
        self.embedding_size = embedding_size

    def process_text(self, text: str):
        return text[:self.default_input_max_characters].strip()

    def generate_text(self, prompt: str, chat_history: list = [], max_output_tokens: int = None,
                            temperature: float = None):

        if not self.generation_model_id:
            self.logger.error("Generation model for Ollama was not set")
            return None
        
        max_output_tokens = max_output_tokens if max_output_tokens else self.default_generation_max_output_tokens
        temperature = temperature if temperature else self.default_generation_temperature

        # Convert chat_history to Ollama format
        messages = []
        for chat in chat_history:
            if isinstance(chat, dict):
                role = "user" if chat.get("role") == "user" else "assistant"
                messages.append({
                    "role": role,
                    "content": chat.get("text", chat.get("content", ""))
                })
        
        # Add current prompt
        messages.append({
            "role": "user", 
            "content": self.process_text(prompt)
        })

        payload = {
            "model": self.generation_model_id,
            "messages": messages,
            "options": {
                "temperature": temperature,
                "num_predict": max_output_tokens
            },
            "stream": False
        }

        try:
            response = requests.post(
                f"{self.api_url}/api/chat",
                json=payload,
                headers={"Content-Type": "application/json"},
                timeout=120
            )
            response.raise_for_status()
            
            result = response.json()
            if "message" in result and "content" in result["message"]:
                return result["message"]["content"]
            else:
                self.logger.error("Unexpected response format from Ollama")
                return None
                
        except requests.exceptions.RequestException as e:
            self.logger.error(f"Error while generating text with Ollama: {e}")
            return None
        except json.JSONDecodeError as e:
            self.logger.error(f"Error parsing Ollama response: {e}")
            return None
    
    def embed_text(self, text: Union[str, List[str]], document_type: str = None):
        if not self.embedding_model_id:
            self.logger.error("Embedding model for Ollama was not set")
            return None
        
        if isinstance(text, str):
            text = [text]
        
        embeddings = []
        for t in text:
            payload = {
                "model": self.embedding_model_id,
                "prompt": self.process_text(t)
            }
            
            try:
                response = requests.post(
                    f"{self.api_url}/api/embeddings",
                    json=payload,
                    headers={"Content-Type": "application/json"},
                    timeout=60
                )
                response.raise_for_status()
                
                result = response.json()
                if "embedding" in result:
                    embeddings.append(result["embedding"])
                else:
                    self.logger.error("No embedding found in Ollama response")
                    return None
                    
            except requests.exceptions.RequestException as e:
                self.logger.error(f"Error while embedding text with Ollama: {e}")
                return None
            except json.JSONDecodeError as e:
                self.logger.error(f"Error parsing Ollama embedding response: {e}")
                return None
        
        return embeddings
    
    def construct_prompt(self, prompt: str, role: str):
        return {
            "role": role,
            "content": prompt,
        }