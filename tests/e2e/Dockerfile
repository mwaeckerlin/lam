FROM python:3.12-slim
WORKDIR /tests
COPY requirements.txt ./
# a throwaway test container runs as root on purpose
RUN pip install --no-cache-dir --root-user-action=ignore -r requirements.txt
COPY . .
