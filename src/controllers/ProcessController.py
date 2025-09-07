from .BaseController import BaseController
from .ProjectController import ProjectController
import os
from langchain_community.document_loaders import TextLoader
from langchain_community.document_loaders import PyMuPDFLoader
from models import ProcessingEnum
from typing import List
from dataclasses import dataclass

@dataclass
class Document:
    page_content: str
    metadata: dict

class ProcessController(BaseController):

    def __init__(self, project_id: str):
        super().__init__()

        self.project_id = project_id
        self.project_path = ProjectController().get_project_path(project_id=project_id)

    def get_file_extension(self, file_id: str):
        return os.path.splitext(file_id)[-1]

    def get_file_loader(self, file_id: str):

        file_ext = self.get_file_extension(file_id=file_id)
        file_path = os.path.join(
            self.project_path,
            file_id
        )

        if not os.path.exists(file_path):
            return None

        if file_ext == ProcessingEnum.TXT.value:
            return TextLoader(file_path, encoding="utf-8")

        if file_ext == ProcessingEnum.PDF.value:
            return PyMuPDFLoader(file_path)
        
        return None

    def get_file_content(self, file_id: str):

        loader = self.get_file_loader(file_id=file_id)
        if loader:
            return loader.load()

        return None

    def process_file_content(self, file_content: list, file_id: str,
                            chunk_size: int=500, overlap_size: int=10):

        file_content_texts = [
            rec.page_content
            for rec in file_content
        ]

        file_content_metadata = [
            rec.metadata
            for rec in file_content
        ]

        # chunks = text_splitter.create_documents(
        #     file_content_texts,
        #     metadatas=file_content_metadata
        # )

        chunks = self.process_simpler_splitter(
            texts=file_content_texts,
            metadatas=file_content_metadata,
            chunk_size=chunk_size,
        )

        return chunks

    def process_simpler_splitter(self, texts: List[str], metadatas: List[dict], chunk_size: int, splitter_tag: str=". "):
        full_text = " ".join(texts)
        
        # For whisper transcripts, try multiple splitting strategies
        if splitter_tag == "\n" and "\n" not in full_text:
            # Fallback to sentence splitting for continuous text
            splitter_tag = ". "
        
        # Split by splitter_tag
        segments = [doc.strip() for doc in full_text.split(splitter_tag) if len(doc.strip()) > 1]
        
        chunks = []
        current_chunk = ""
        
        for segment in segments:
            # Check if adding this segment would exceed chunk_size
            potential_chunk = current_chunk + segment + splitter_tag
            
            if len(potential_chunk) > chunk_size and len(current_chunk) > 0:
                # Save current chunk and start new one
                chunks.append(Document(
                    page_content=current_chunk.strip(),
                    metadata=metadatas[0] if metadatas else {}
                ))
                current_chunk = segment + splitter_tag
            else:
                current_chunk = potential_chunk
        
        # Add final chunk if it has content
        if len(current_chunk.strip()) > 0:
            chunks.append(Document(
                page_content=current_chunk.strip(),
                metadata=metadatas[0] if metadatas else {}
            ))
        
        return chunks


    

